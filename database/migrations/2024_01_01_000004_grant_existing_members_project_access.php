<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grant existing team members full access to all projects.
 * This ensures backward compatibility when enabling granular permissions.
 *
 * NOTE: This is a one-shot migration at upgrade time only. Team members invited
 * after enablement have no project_user row until an admin assigns access via
 * the Access Matrix or Permissions API.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Get all team members with 'member' or 'viewer' role
        $teamMembers = DB::table('team_user')
            ->whereIn('role', ['member', 'viewer'])
            ->get();

        foreach ($teamMembers as $teamMember) {
            // Get all projects for this team
            $projects = DB::table('projects')
                ->where('team_id', $teamMember->team_id)
                ->get();

            foreach ($projects as $project) {
                // Check if access already exists
                $exists = DB::table('project_user')
                    ->where('project_id', $project->id)
                    ->where('user_id', $teamMember->user_id)
                    ->exists();

                if (! $exists) {
                    DB::table('project_user')->insert([
                        'project_id' => $project->id,
                        'user_id' => $teamMember->user_id,
                        'permissions' => json_encode([
                            'view' => true,
                            'deploy' => true,
                            'manage' => true,
                            'delete' => true,
                        ]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // This migration is not reversible as we don't know which records were pre-existing
    }
};
