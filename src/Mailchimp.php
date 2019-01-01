<?php namespace NZTim\Mailchimp;

use NZTim\Mailchimp\Exception\MailchimpBadRequestException;
use NZTim\Mailchimp\Exception\MailchimpException;
use Throwable;

class Mailchimp
{
    protected $api;
    protected $member;

    public function __construct($apikey, $api = null)
    {
        if (!is_string($apikey)) {
            throw new MailchimpException("Mailchimp API key is required - use the 'MC_KEY' .env value");
        }
        if (is_null($api)) {
            $api = new MailchimpApi($apikey);
        }
        $this->api = $api;
    }

    // Get information for specified list
    public function getList(string $listId): array
    {
        $results = $this->api->getList($listId);
        return $results ?? [];
    }

    // Gets all available lists
    public function getLists(): array
    {
        $results = $this->api->getLists();
        return $results['lists'] ?? [];
    }

    // Gets all available list segments.
    public function getListSegments(string $listId): array
    {
        $results = $this->api->getListSegments($listId);
        return $results['segments'] ?? [];
    }

    // Get specific member record
    public function getMember(string $listId, string $email, bool $hashed = false): array
    {
        $this->checkListExists($listId);
        if (!$hashed)
            $email = md5(strtolower($email));
        try 
        {
            $member = $this->api->getMember($listId, $email);
        } catch (Throwable $e) {
            if ($this->api->responseCodeNotFound()) {
                return [];
            }
            throw $e;
        }
        if (!$this->memberStatusIsValid($member)) {
            throw new MailchimpException('Unknown error, status value not found: ' . var_export($member, true));
        }
        return $member;
    }
        
    // Determines the status of a subscriber
    // Possible responses: 'subscribed', 'unsubscribed', 'cleaned', 'pending', 'transactional' or 'not found'
    public function status(string $listId, string $email): string
    {
        return $this->getMember($listId, $email)['status'] ?? false;
    }

    // Checks to see if an email address is subscribed to a list
    public function check(string $listId, string $email): bool
    {
        $result = $this->status($listId, $email);
        return in_array($result, ['subscribed',  'pending']);
    }

    // Add a member to the list or update an existing member
    // Ensures that existing subscribers are not asked to reconfirm
    public function subscribe(string $listId, string $email, bool $confirm = true, array $mergeFields = [], array $tags = []): array
    {
        if ($this->status($listId, $email) == 'subscribed') {
            $confirm = false;
        }
        $result = $this->api->addUpdate($listId, $email, $confirm, $mergeFields, $tags);
        return $result ?? [];
    }

    public function addUpdateMember(string $listId, Member $member): array
    {
        // Get current member info, if exists.
        $currentMember = $this->getMember($listId, $member->hash(), $hashed = true);

        // Check if current member exists.
        if ($currentMember)
        {
            // Check if member is subscribed.
            if ($currentMember['email_address'] == 'subscribed')
                $member->confirm(false);

            // Update tags, where applicable.
            $result = $this->updateMemberTags($listId, $member, $currentMember['email_address']);
        }

        // Update member details
        $result = $this->api->addUpdateMember($listId, $member);

        return $result ?? [];
    }

    // Add member to a specific tag segment
    public function addMemberTag(string $listId, string $segmentId, $email): array 
    {
        $result = $this->api->addMemberTag($listId, $segmentId, $email);
        return $result ?? [];
    }

    public function unsubscribe(string $listId, string $email): array
    {
        if (!$this->check($listId, $email)) {
            return [];
        }
        $result = $this->api->unsubscribe($listId, $email);
        return $result ?? [];
    }

    // Make an API call directly
    public function api(string $method, string $endpoint, array $data = []): array
    {
        $endpoint = '/' . ltrim($endpoint, '/'); // Ensure leading slash is present
        return $this->api->call($method, $endpoint, $data);
    }

    protected function updateMemberTags(string $listId, Member $member, string $email): array 
    {
        // Get member tags
        try 
        {
            $result = $this->api->getMemberTags($listId, $member->hash());
        } 
        catch (MailchimpException $e) 
        {
            if ($this->api->responseCodeNotFound()) {
                return [];
            }
            \Log::error([
                'error' => $e->getMessage(),
                'data' => $member
            ]);
        }

        if (isset($result['tags']))
        {
            $newTags = $member->parameters()['tags'];

            // Loop through current member tags.
            $memberTags = [];
            foreach ($result['tags'] as $tag)
            {
                // Determine if tag no longer applies.  If not, remote member from tag segment.
                if (!in_array($tag['name'], $newTags))
                    $this->removeMemberTag($listId, $tag['id'], $member->hash());

                $memberTags[] = $tag['name'];
            }
            
            // Loop through new tags.
            $segments = [];
            foreach ($newTags as $tag)
            {
                // Determine if tag is not currently available.  If not, add member to tag segment.
                if (!in_array($tag, $memberTags))
                {
                    // Retrieve list tags (if not already retrieved)
                    if (!$segments)
                        $segments = $this->getListSegments($listId);

                    // Retrieve segment (first create tag, if not already available)
                    if ($segment = $this->getListSegment($listId, $segments, $tag))
                        $result = $this->addMemberTag($listId, $segment['id'], $email);
                }
            }
        }

        return $result ?? [];
    }

    protected function getListSegment(string $listId, array $segments, string $tag) : array
    {
        // If segment exists, return existing segment.
        foreach ($segments as $segment)
        {
            if ($segment['name'] === $tag)
                return $segment;
        }

        // Segment does not exist, first create it.
        $result = $this->api->createSegment($listId, $tag);
        return $result ?? [];
    }

    protected function removeMemberTag(string $listId, string $segmentId, string $subscriber_hash)
    {
        // Remove member from segment (tag).
        try 
        {
            $result = $this->api->removeMemberTag($listId, $segmentId, $subscriber_hash);
        } 
        catch (MailchimpException $e) 
        {
            if ($this->api->responseCodeNotFound()) {
                return [];
            }
            \Log::error([
                'error' => $e->getMessage(),
                'data' => [
                    'listId' => $listId,
                    'segmentId' => $segmentId,
                    'subscriber_hash' => $subscriber_hash,
                ]
            ]);
        }
    }

    protected function checkListExists(string $listId)
    {
        try {
            $this->api->getList($listId);
        } catch (Throwable $e) {
            if ($this->api->responseCodeNotFound()) {
                throw new MailchimpBadRequestException('Mailchimp API error: list id:'.$listId.' does not exist');
            }
            throw $e;
        }
    }

    protected function memberStatusIsValid($member): bool
    {
        if (!isset($member['status'])) {
            return false;
        }
        return in_array($member['status'], ['subscribed', 'unsubscribed', 'cleaned', 'pending', 'transactional']);
    }
}
