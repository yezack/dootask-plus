<?php

namespace App\Scim;

use App\Scim\ScimDepartmentSynchronizer;

class ScimWebhookUserSynchronizer
{
    public function __construct(private ?ScimClient $client = null)
    {
    }

    public function sync(string $uri, array $events): string
    {
        if (str_starts_with($uri, '/Groups/')) {
            return $this->syncGroup($uri, $events);
        }
        if (str_starts_with($uri, '/Organizations/')) {
            return $this->syncOrganization($uri, $events);
        }
        return $this->syncUser($uri, $events);
    }

    private function syncUser(string $uri, array $events): string
    {
        try {
            $scimUser = $this->client()->getUserByUri($uri);
            $result = ScimUserMapper::sync($scimUser);
        } catch (ScimResourceNotFoundException $e) {
            if (!in_array('urn:ietf:params:scim:event:prov:delete', $events, true)) {
                throw $e;
            }
            $result = ScimUserMapper::disableByExternalId($this->externalUserId($uri));
        }

        if ($result === 'failed') {
            throw new \RuntimeException('SCIM 用户同步失败');
        }
        return $result;
    }

    private function syncGroup(string $uri, array $events): string
    {
        if ($this->isDelete($events)) {
            return 'group-delete-recorded';
        }
        $groupId = $this->externalIdFromUri($uri, '/Groups/');
        $result = app(ScimDepartmentSynchronizer::class)->syncSingleGroup($this->client(), $groupId);
        if ($result['error'] !== null) {
            throw new \RuntimeException($result['error']);
        }
        return $result['updated'] ? 'group-updated' : 'group-skipped';
    }

    private function syncOrganization(string $uri, array $events): string
    {
        if ($this->isDelete($events)) {
            return 'organization-delete-recorded';
        }
        $orgId = $this->externalIdFromUri($uri, '/Organizations/');
        $result = app(ScimDepartmentSynchronizer::class)->syncSingleOrganization($this->client(), $orgId);
        if ($result['error'] !== null) {
            throw new \RuntimeException($result['error']);
        }
        return $result['updated'] ? 'organization-updated' : 'organization-skipped';
    }

    private function client(): ScimClient
    {
        return $this->client ??= new ScimClient();
    }

    private function isDelete(array $events): bool
    {
        return in_array('urn:ietf:params:scim:event:prov:delete', $events, true);
    }

    private function externalUserId(string $uri): string
    {
        return rawurldecode(substr($uri, strlen('/Users/')));
    }

    private function externalIdFromUri(string $uri, string $prefix): string
    {
        return rawurldecode(substr($uri, strlen($prefix)));
    }
}
