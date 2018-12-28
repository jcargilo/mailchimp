<?php namespace NZTim\Mailchimp;

use NZTim\Mailchimp\Exception\MailchimpBadRequestException;
use NZTim\Mailchimp\Exception\MailchimpException;
use Throwable;

class Mailchimp
{
    protected $api;

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
        
     // Determines the status of a subscriber
     // Possible responses: 'subscribed', 'unsubscribed', 'cleaned', 'pending', 'transactional' or 'not found'
    public function status(string $listId, string $email): string
    {
        $this->checkListExists($listId);
        $memberId = md5(strtolower($email));
        try {
            $member = $this->api->getMember($listId, $memberId);
        } catch (Throwable $e) {
            if ($this->api->responseCodeNotFound()) {
                return 'not found';
            }
            throw $e;
        }
        if (!$this->memberStatusIsValid($member)) {
            throw new MailchimpException('Unknown error, status value not found: ' . var_export($member, true));
        }
        return $member['status'];
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
        if ($this->status($listId, $member->parameters()['email_address']) == 'subscribed') {
            $member->confirm(false);
        }
        $result = $this->api->addUpdateMember($listId, $member);
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
