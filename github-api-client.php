<?php
if(!defined('ABSPATH')) {
    exit;
}

class GitHubAPIClient
{
    public $owner;
    public $repo;
    public $access_token;

    function __construct($repo_url, $access_token)
    {
        $repo_url = preg_replace('/^https?:\/\//', '', $repo_url);

        $this->owner = explode('/', $repo_url)[1];
        $this->repo = explode('/', $repo_url)[2];
        $this->access_token = $access_token;
    }

    function get_all_workflows()
    {
        $url = "https://api.github.com/repos/$this->owner/$this->repo/actions/workflows";
        $headers = array(
            'Authorization' => 'Bearer ' . $this->access_token,
            'Content-Type' => 'application/json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'Accept' => 'application/vnd.github.v3+json',
        );

        $response = wp_remote_request($url, array(
            'method' => 'GET',
            'headers' => $headers
        ));

        return $response;
    }

    function trigger_github_action($workflow_id)
    {
        $url = "https://api.github.com/repos/$this->owner/$this->repo/actions/workflows/$workflow_id/dispatches";
        $headers = array(
            'Authorization' => 'Bearer ' . $this->access_token,
            'Content-Type' => 'application/json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'Accept' => 'application/vnd.github.v3+json'
        );
        $body = json_encode(array('ref' => 'main'));

        $response = wp_remote_request($url, array(
            'method' => 'POST',
            'headers' => $headers,
            'body' => $body,
        ));

        return $response;
    }

    function trigger_all_gh_actions()
    {
        $workflows = $this->get_all_workflows();
        $workflows = json_decode($workflows['body'], true)['workflows'];

        foreach ($workflows as $workflow) {
            $this->trigger_github_action($workflow['id']);
        }
    }
}
?>