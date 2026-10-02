<?php

namespace CorelixIo\Platform\Services;

use App\Models\S3Storage;

class RcloneService
{
    /**
     * Escape a value for safe use in shell commands.
     */
    private static function escape(string $value): string
    {
        return escapeshellarg($value);
    }

    /**
     * The rclone Docker image to use for backup operations.
     */
    public static function getRcloneImage(): string
    {
        return config('corelix-platform.backup_encryption.rclone_image', 'rclone/rclone:latest');
    }

    /**
     * Check if encryption is enabled on an S3 storage instance.
     */
    public static function isEncryptionEnabled(?S3Storage $s3): bool
    {
        if (is_null($s3)) {
            return false;
        }

        return (bool) data_get($s3, 'encryption_enabled', false)
            && filled(data_get($s3, 'encryption_password'));
    }

    /**
     * Obscure a password using rclone's obscure algorithm.
     *
     * rclone expects passwords in environment variables to be "obscured".
     * The algorithm is AES-256-CTR with a well-known fixed key, prepended
     * with a random 16-byte IV, then base64url-encoded (no padding).
     *
     * This is NOT encryption for security - it only prevents casual viewing.
     * The fixed key is publicly known from rclone's source code.
     */
    public static function obscurePassword(string $password): string
    {
        // rclone's fixed key (from fs/config/obscure/obscure.go)
        $key = hex2bin('9c935b48730a554d6bfd7c63c886a92bd390198eb8128afbf4de162b8b95f638');
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($password, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);

        // rclone uses base64 URL encoding without padding
        return rtrim(strtr(base64_encode($iv.$encrypted), '+/', '-_'), '=');
    }

    /**
     * Build the content for an rclone environment file.
     *
     * Uses the RCLONE_CONFIG_<REMOTE>_<OPTION> pattern so rclone
     * configures itself entirely from environment variables (no config file).
     *
     * Returns the content as a string, one VAR=VALUE per line.
     */
    public static function buildEnvFileContent(S3Storage $s3): string
    {
        $vars = [
            // Base S3 remote configuration
            'RCLONE_CONFIG_S3REMOTE_TYPE' => 's3',
            'RCLONE_CONFIG_S3REMOTE_PROVIDER' => 'Other',
            'RCLONE_CONFIG_S3REMOTE_ACCESS_KEY_ID' => $s3->key,
            'RCLONE_CONFIG_S3REMOTE_SECRET_ACCESS_KEY' => $s3->secret,
            'RCLONE_CONFIG_S3REMOTE_ENDPOINT' => $s3->endpoint,
            'RCLONE_CONFIG_S3REMOTE_REGION' => $s3->region,
            'RCLONE_CONFIG_S3REMOTE_FORCE_PATH_STYLE' => 'true',
        ];

        if (self::isEncryptionEnabled($s3)) {
            $filenameEncryption = data_get($s3, 'filename_encryption', 'off');
            $directoryNameEncryption = data_get($s3, 'directory_name_encryption', false);

            $vars['RCLONE_CONFIG_ENCRYPTED_TYPE'] = 'crypt';
            $vars['RCLONE_CONFIG_ENCRYPTED_REMOTE'] = "s3remote:{$s3->bucket}";
            $vars['RCLONE_CONFIG_ENCRYPTED_PASSWORD'] = self::obscurePassword($s3->encryption_password);

            if (filled($s3->encryption_salt)) {
                $vars['RCLONE_CONFIG_ENCRYPTED_PASSWORD2'] = self::obscurePassword($s3->encryption_salt);
            }

            $vars['RCLONE_CONFIG_ENCRYPTED_FILENAME_ENCRYPTION'] = $filenameEncryption;
            $vars['RCLONE_CONFIG_ENCRYPTED_DIRECTORY_NAME_ENCRYPTION'] = $directoryNameEncryption ? 'true' : 'false';
        }

        return collect($vars)
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode("\n");
    }

    /**
     * Get the rclone remote target path for upload/download.
     *
     * When encryption is enabled, use the "encrypted:" remote (crypt overlay).
     * When not encrypted, use "s3remote:bucket" directly.
     */
    public static function getRemoteTarget(S3Storage $s3, string $remotePath): string
    {
        if (self::isEncryptionEnabled($s3)) {
            // The crypt remote's base is already set to s3remote:bucket via env vars
            return "encrypted:{$remotePath}";
        }

        return "s3remote:{$s3->bucket}{$remotePath}";
    }

    /**
     * Build shell commands to upload a local file to S3 via rclone.
     *
     * This creates a temporary env file on the server, starts an rclone
     * container, copies the file, then cleans up.
     *
     * @param  string  $backupLocation  Absolute path to the local backup file
     * @param  string  $remotePath  The S3 path (e.g., /backups/databases/team/db/)
     * @param  string  $containerName  Name for the rclone helper container
     * @param  string  $network  Docker network to attach the container to
     * @return array Shell commands to execute via instant_remote_process()
     */
    public static function buildUploadCommands(
        S3Storage $s3,
        string $backupLocation,
        string $remotePath,
        string $containerName,
        string $network
    ): array {
        $envFileContent = self::buildEnvFileContent($s3);
        $envFilePath = "/tmp/rclone-env-{$containerName}";
        $envBase64 = base64_encode($envFileContent);
        $rcloneImage = self::getRcloneImage();
        $remoteTarget = self::getRemoteTarget($s3, $remotePath);

        $commands = [];

        $escContainer = self::escape($containerName);
        $escNetwork = self::escape($network);
        $escEnvFile = self::escape($envFilePath);
        $escBackup = self::escape($backupLocation);
        $escImage = self::escape($rcloneImage);
        $escRemote = self::escape($remoteTarget);

        // Clean up any existing container
        $commands[] = "docker rm -f {$escContainer} 2>/dev/null || true";

        // Write env file to server (base64 to avoid escaping issues)
        $commands[] = "echo '{$envBase64}' | base64 -d > {$escEnvFile}";
        $commands[] = "chmod 600 {$escEnvFile}";

        // Start rclone container with env file and volume mount
        // --entrypoint "" overrides the image's ENTRYPOINT ["rclone"] so sleep runs as a shell command
        $commands[] = "docker run -d --network {$escNetwork} --name {$escContainer}"
            ." --entrypoint ''"
            ." --env-file {$escEnvFile}"
            ." -v {$escBackup}:{$escBackup}:ro"
            ." {$escImage} sleep 3600";

        // Upload via rclone copy
        $commands[] = "docker exec {$escContainer} rclone copy"
            ." {$escBackup} {$escRemote}";

        return $commands;
    }

    /**
     * Build shell commands to download a file from S3 via rclone.
     *
     * @param  string  $remotePath  The S3 path (e.g., /backups/databases/team/db/file.dmp)
     * @param  string  $localPath  Where to store the downloaded file inside the container
     * @param  string  $containerName  Name for the rclone helper container
     * @param  string  $network  Docker network to attach to
     * @return array Shell commands to execute
     */
    public static function buildDownloadCommands(
        S3Storage $s3,
        string $remotePath,
        string $localPath,
        string $containerName,
        string $network
    ): array {
        $envFileContent = self::buildEnvFileContent($s3);
        $envFilePath = "/tmp/rclone-env-{$containerName}";
        $envBase64 = base64_encode($envFileContent);
        $rcloneImage = self::getRcloneImage();

        // For download, we need file path and directory separately
        $remoteDir = dirname($remotePath);
        $remoteFile = basename($remotePath);
        $remoteTarget = self::getRemoteTarget($s3, $remoteDir);
        $localDir = dirname($localPath);

        $commands = [];

        $escContainer = self::escape($containerName);
        $escNetwork = self::escape($network);
        $escEnvFile = self::escape($envFilePath);
        $escImage = self::escape($rcloneImage);
        $escRemote = self::escape($remoteTarget);
        $escLocalDir = self::escape($localDir);
        $escFile = self::escape($remoteFile);

        // Clean up any existing container
        $commands[] = "docker rm -f {$escContainer} 2>/dev/null || true";

        // Write env file
        $commands[] = "echo '{$envBase64}' | base64 -d > {$escEnvFile}";
        $commands[] = "chmod 600 {$escEnvFile}";

        // Start rclone container (--entrypoint "" overrides image's ENTRYPOINT ["rclone"])
        $commands[] = "docker run -d --network {$escNetwork} --name {$escContainer}"
            ." --entrypoint ''"
            ." --env-file {$escEnvFile}"
            ." {$escImage} sleep 3600";

        // Download via rclone copy (copies specific file to local directory)
        $commands[] = "docker exec {$escContainer} rclone copy"
            ." {$escRemote} {$escLocalDir}"
            ." --include {$escFile}";

        return $commands;
    }

    /**
     * Build shell commands to delete files from S3 via rclone.
     *
     * Used when filename encryption is enabled and Laravel's S3 driver
     * can't match the encrypted filenames.
     *
     * @param  array  $filenames  Plaintext file paths to delete
     * @param  string  $containerName  Name for the rclone helper container
     * @return array Shell commands to execute
     */
    public static function buildDeleteCommands(
        S3Storage $s3,
        array $filenames,
        string $containerName
    ): array {
        $envFileContent = self::buildEnvFileContent($s3);
        $envFilePath = "/tmp/rclone-env-{$containerName}";
        $envBase64 = base64_encode($envFileContent);
        $rcloneImage = self::getRcloneImage();

        $commands = [];

        $escContainer = self::escape($containerName);
        $escEnvFile = self::escape($envFilePath);
        $escImage = self::escape($rcloneImage);

        // Write env file
        $commands[] = "echo '{$envBase64}' | base64 -d > {$escEnvFile}";
        $commands[] = "chmod 600 {$escEnvFile}";

        // Start rclone container (--entrypoint "" overrides image's ENTRYPOINT ["rclone"])
        $commands[] = "docker run -d --name {$escContainer}"
            ." --entrypoint ''"
            ." --env-file {$escEnvFile}"
            ." {$escImage} sleep 3600";

        // Delete each file via rclone
        foreach ($filenames as $filename) {
            $remoteTarget = self::getRemoteTarget($s3, $filename);
            $escRemote = self::escape($remoteTarget);
            $commands[] = "docker exec {$escContainer} rclone deletefile {$escRemote}";
        }

        // Cleanup
        $commands[] = "docker rm -f {$escContainer} 2>/dev/null || true";
        $commands[] = "rm -f {$escEnvFile}";

        return $commands;
    }

    /**
     * Build cleanup commands to remove rclone container and env file.
     */
    public static function buildCleanupCommands(string $containerName): array
    {
        $escContainer = self::escape($containerName);
        $escEnvFile = self::escape("/tmp/rclone-env-{$containerName}");

        return [
            "docker rm -f {$escContainer} 2>/dev/null || true",
            "rm -f {$escEnvFile} 2>/dev/null || true",
        ];
    }
}
