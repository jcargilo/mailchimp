<?php namespace NZTim\Mailchimp;

use NZTim\Mailchimp\Exception\MailchimpBadRequestException;
use NZTim\Mailchimp\Exception\MailchimpException;
use NZTim\Mailchimp\Exception\MailchimpInternalErrorException;
use Requests;
use Requests_Auth_Basic;
use Requests_Response;

class MailchimpApi
{
    protected $apikey;
    protected $baseurl = 'https://<dc>.api.mailchimp.com/3.0';
    protected $responseCode;

    public function __construct(string $apikey)
    {
        $this->apikey = $apikey;
        $exploded = explode('-', $apikey);
        $this->baseurl = str_replace('<dc>', array_pop($exploded), $this->baseurl);
    }

    // API calls --------------------------------------------------------------

    public function getLists(): array
    {
        return $this->call('get', '/lists');
    }

    public function getList(string $listId): array
    {
        return $this->call('get', '/lists/' . $listId);
    }

    public function getListSegments(string $listId): array
    {
        return $this->call('get', "/lists/{$listId}/segments");
    }

    public function createSegment(string $listId, string $name): array
    {
        $data = [
            'name' => $name
        ];
        return $this->call('post', "/lists/{$listId}/segments", $data);
    }

    public function getMember(string $listId, string $memberId): array
    {
        return $this->call('get', "/lists/{$listId}/members/{$memberId}");
    }

    public function getMemberTags(string $listId, string $subscriber_hash): array
    {
        return $this->call('get', "/lists/{$listId}/members/{$subscriber_hash}/tags");
    }

    public function addMemberTag(string $listId, string $segment_id, string $email)
    {
        $data = [
            'email_address' => $email
        ];
        return $this->call('post', "/lists/{$listId}/segments/{$segment_id}/members", $data);
    }

    public function removeMemberTag(string $listId, string $segment_id, string $subscriber_hash)
    {
        return $this->call('delete', "/lists/{$listId}/segments/{$segment_id}/members/{$subscriber_hash}");
    }

    public function addUpdate(string $listId, string $email, bool $confirm, array $merge, array $tags): array
    {
        $email = strtolower($email);
        $memberId = md5($email);
        $data = [
            'email_address' => $email,
            'status_if_new' => $confirm ? 'pending' : 'subscribed',
            'status'        => $confirm ? 'pending' : 'subscribed',
        ];
        // Empty array doesn't work
        if ($merge) {
            $data['merge_fields'] = $merge;
        }
        if ($tags) {
            $data['tags'] = $tags;
        }
        return $this->call('put', "/lists/{$listId}/members/{$memberId}", $data);
    }

    public function addUpdateMember(string $listId, Member $member): array
    {
        return $this->call('put', "/lists/{$listId}/members/{$member->hash()}", $member->parameters());
    }

    public function unsubscribe(string $listId, string $email): array
    {
        $memberId = md5(strtolower($email));
        return $this->call('put', "/lists/{$listId}/members/{$memberId}", ['email_address' => $email, 'status_if_new' => 'unsubscribed', 'status' => 'unsubscribed']);
    }

    // HTTP -------------------------------------------------------------------

    public function call(string $method, string $endpoint, array $data = []): array
    {
        $method = strtolower($method);
        if (!in_array($method, ['get', 'put', 'post', 'delete', 'patch'])) {
            throw new MailchimpException('Invalid API call method: ' . $method);
        }
        if (in_array($method, ['get', 'delete'])) {
            $url = $this->baseurl . $endpoint;
            $url .= $data ? '?' . http_build_query($data) : '';
            $response = Requests::$method($url, $this->headers(), $this->options());
        } else {
            $response = Requests::$method(
                $this->baseurl . $endpoint,
                $this->headers(),
                json_encode($data),
                $this->options()
            );
        }
        $this->responseCode = intval($response->status_code);
        if ($this->responseCode >= 400) {
            $this->apiError($response);
        }
        return json_decode($response->body, true) ?? [];
    }

    protected function options(): array
    {
        return [
            'auth' => new Requests_Auth_Basic(['mcuser', $this->apikey]),
        ];
    }

    protected function headers(): array
    {
        return [];
    }

    protected function apiError(Requests_Response $response)
    {
        $info = var_export(json_decode($response->body, true), true);
        $message = "Mailchimp API error (" . $response->status_code . "): " . $info;
        if ($this->responseCode <= 499) {
            throw new MailchimpBadRequestException($message, $this->responseCode, null, $response->body);
        }
        throw new MailchimpInternalErrorException($message, $this->responseCode);
    }

    public function responseCode(): int
    {
        return $this->responseCode;
    }

    public function responseCodeNotFound(): bool
    {
        return $this->responseCode == 404;
    }
}
