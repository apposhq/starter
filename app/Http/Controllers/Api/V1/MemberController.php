<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Api\V1\MemberResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @tags Members
 */
class MemberController extends ApiController
{
    /**
     * List members
     *
     * Members of the team the API key belongs to, oldest first.
     *
     * Paged by cursor rather than page number. Offset paging re-runs the query for every page, so a
     * member joining or leaving mid-walk shifts every row after it — the caller silently skips or
     * repeats records. A cursor names the row it left off at, which stays correct while the table
     * changes underneath, and costs a key lookup instead of a scan on deep pages.
     *
     * Pass the `next_cursor` from the previous response as `cursor` to continue.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $team = $request->attributes->get('api_team');

        return MemberResource::collection(
            $team->members()
                ->orderBy('team_members.created_at')
                // The join's created_at is not unique on its own — a team seeded in one transaction
                // shares a timestamp across rows — so the id breaks ties and keeps the cursor total.
                ->orderBy('users.id')
                ->cursorPaginate(
                    perPage: $this->perPage($request),
                )
        );
    }

    /**
     * Retrieve a member
     *
     * Responds 404 rather than 403 when the member belongs to another team, so the API never confirms
     * that an id it cannot show exists.
     */
    public function show(Request $request, string $member): MemberResource
    {
        return new MemberResource(
            $request->attributes->get('api_team')->members()->find($member)
                ?? throw new NotFoundHttpException('Member not found.')
        );
    }
}
