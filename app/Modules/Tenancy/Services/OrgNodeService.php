<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\OrgNode;

/**
 * Creates org-hierarchy nodes with a maintained materialized path and depth
 * (docs/03 §1). The path ("/rootId/childId/...") makes ancestor-or-self checks — used by
 * the RBAC permission resolver — a cheap string prefix test instead of recursive joins.
 */
class OrgNodeService
{
    public function create(string $institutionId, string $type, string $name, ?OrgNode $parent = null, ?string $code = null): OrgNode
    {
        $node = new OrgNode([
            'institution_id' => $institutionId,
            'parent_id' => $parent?->id,
            'type' => $type,
            'name' => $name,
            'code' => $code,
            'depth' => $parent ? $parent->depth + 1 : 0,
            'path' => '/', // finalized below once the id exists
        ]);
        $node->save();

        // Path includes the node's own id so prefix tests cover ancestor-OR-self.
        $node->path = ($parent ? rtrim($parent->path, '/') : '').'/'.$node->id;
        $node->save();

        return $node;
    }

    /** True if $ancestor is an ancestor of (or equal to) $node, via the materialized path. */
    public function isAncestorOrSelf(OrgNode $ancestor, OrgNode $node): bool
    {
        return self::pathCovers($ancestor->path, $node->path);
    }

    /** Prefix test with a segment boundary so "/a/b" does not match "/a/bc". */
    public static function pathCovers(string $ancestorPath, string $nodePath): bool
    {
        return $nodePath === $ancestorPath || str_starts_with($nodePath, rtrim($ancestorPath, '/').'/');
    }
}
