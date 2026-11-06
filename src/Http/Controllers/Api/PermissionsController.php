<?php

namespace CorelixIo\Platform\Http\Controllers\Api;

use CorelixIo\Platform\Models\ProjectUser;
use CorelixIo\Platform\Services\PermissionService;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class PermissionsController extends Controller
{
    #[OA\Get(
        summary: 'List Project Access',
        description: 'Get all users with access to a project.',
        path: '/projects/{uuid}/access',
        operationId: 'list-project-access',
        security: [['bearerAuth' => []]],
        tags: ['Permissions'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of users with project access.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function listProjectAccess(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if ($denied = $this->denyUnlessTeamAdmin($request->user(), $teamId)) {
            return $denied;
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $access = ProjectUser::where('project_id', $project->id)
            ->with('user:id,name,email')
            ->get()
            ->map(fn ($pu) => [
                'id' => $pu->id,
                'user_id' => $pu->user_id,
                'user_name' => $pu->user->name,
                'user_email' => $pu->user->email,
                'permissions' => $pu->permissions,
                'permission_level' => $pu->getPermissionLevel(),
            ]);

        return response()->json(serializeApiResponse($access));
    }

    #[OA\Post(
        summary: 'Grant Project Access',
        description: 'Grant a user access to a project.',
        path: '/projects/{uuid}/access',
        operationId: 'grant-project-access',
        security: [['bearerAuth' => []]],
        tags: ['Permissions'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Access granted successfully.'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function grantProjectAccess(Request $request)
    {
        $allowedFields = ['user_id', 'permission_level'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if ($denied = $this->denyUnlessTeamAdmin($request->user(), $teamId)) {
            return $denied;
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'permission_level' => ['sometimes', 'string', 'in:view_only,deploy,full_access'],
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $targetUser = User::find($request->user_id);

        if (! $targetUser->teams()->where('teams.id', $teamId)->exists()) {
            return response()->json(['message' => 'User is not a member of this team.'], 400);
        }

        $existing = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $targetUser->id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'User already has access to this project.'], 400);
        }

        $permissionLevel = $request->permission_level ?? 'view_only';

        $projectUser = PermissionService::grantProjectAccess($targetUser, $project, $permissionLevel);

        return response()->json([
            'message' => 'Access granted successfully.',
            'id' => $projectUser->id,
        ], 201);
    }

    #[OA\Patch(
        summary: 'Update Project Access',
        description: 'Update user permissions on a project.',
        path: '/projects/{uuid}/access/{user_id}',
        operationId: 'update-project-access',
        security: [['bearerAuth' => []]],
        tags: ['Permissions'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, description: 'User ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Access updated successfully.'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function updateProjectAccess(Request $request)
    {
        $allowedFields = ['permission_level'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if ($denied = $this->denyUnlessTeamAdmin($request->user(), $teamId)) {
            return $denied;
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $validator = Validator::make($request->all(), [
            'permission_level' => ['required', 'string', 'in:view_only,deploy,full_access'],
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $projectUser = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $request->user_id)
            ->first();

        if (! $projectUser) {
            return response()->json(['message' => 'User access not found.'], 404);
        }

        $projectUser->setPermissions(ProjectUser::getPermissionsForLevel($request->permission_level))->save();

        return response()->json(['message' => 'Access updated successfully.']);
    }

    #[OA\Delete(
        summary: 'Revoke Project Access',
        description: 'Revoke user access to a project.',
        path: '/projects/{uuid}/access/{user_id}',
        operationId: 'revoke-project-access',
        security: [['bearerAuth' => []]],
        tags: ['Permissions'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, description: 'User ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Access revoked successfully.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function revokeProjectAccess(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if ($denied = $this->denyUnlessTeamAdmin($request->user(), $teamId)) {
            return $denied;
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $targetUser = User::find($request->user_id);
        if (! $targetUser) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $deleted = PermissionService::revokeProjectAccess($targetUser, $project);

        if (! $deleted) {
            return response()->json(['message' => 'User access not found.'], 404);
        }

        return response()->json(['message' => 'Access revoked successfully.']);
    }

    #[OA\Get(
        summary: 'Check User Permission',
        description: 'Check if a user has a specific permission on a project.',
        path: '/projects/{uuid}/access/{user_id}/check',
        operationId: 'check-project-permission',
        security: [['bearerAuth' => []]],
        tags: ['Permissions'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Project UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'path', required: true, description: 'User ID', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'permission', in: 'query', required: true, description: 'Permission to check', schema: new OA\Schema(type: 'string', enum: ['view', 'deploy', 'manage', 'delete'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Permission check result.'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function checkPermission(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if ($denied = $this->denyUnlessTeamAdmin($request->user(), $teamId)) {
            return $denied;
        }

        $permission = $request->query('permission');
        if (! in_array($permission, ['view', 'deploy', 'manage', 'delete'])) {
            return response()->json(['message' => 'Invalid permission type.'], 400);
        }

        $project = Project::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $targetUser = User::find($request->user_id);
        if (! $targetUser) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (! $targetUser->teams()->where('teams.id', $teamId)->exists()) {
            return response()->json(['message' => 'User is not a member of this team.'], 400);
        }

        $hasPermission = PermissionService::hasProjectPermission($targetUser, $project, $permission);

        return response()->json(['has_permission' => $hasPermission]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function denyUnlessTeamAdmin(User $user, int $teamId): ?\Illuminate\Http\JsonResponse
    {
        if (PermissionService::isTeamAdmin($user, $teamId)) {
            return null;
        }

        return response()->json(['message' => 'Unauthorized. Admin or owner role required.'], 403);
    }
}
