<?php

namespace App\Enums;

enum BuildPackTypes: string
{
    case NIXPACKS = 'nixpacks';
    case STATIC = 'static';
    case DOCKERFILE = 'dockerfile';
    case DOCKERCOMPOSE = 'dockercompose';
    case RAILPACK = 'railpack';
    // [CORELIX ENHANCED: Additional build types — Cloud Native Buildpacks. Railpack is now a native upstream case.]
    case HEROKU = 'heroku';
    case PAKETO = 'paketo';
    // [END CORELIX ENHANCED]
}
