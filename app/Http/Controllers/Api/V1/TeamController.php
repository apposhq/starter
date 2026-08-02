<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Api\V1\TeamResource;
use App\Models\Team;
use Illuminate\Http\Request;

/**
 * @tags Team
 */
class TeamController extends ApiController
{
    /**
     * Retrieve the team
     *
     * Returns the team the API key belongs to. A key is issued by a team and acts only on that team, so
     * there is no team id to pass and no way to reach another customer's data with it.
     */
    public function show(Request $request): TeamResource
    {
        return new TeamResource($request->attributes->get('api_team'));
    }
}
