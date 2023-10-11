<?php 
/** 
 * @package Trigger GitHub Action Plugin
 * @version 1.0.0
 */

use PgSql\Lob;
include (plugin_dir_path(__FILE__) . '/github-api-client.php');

/* 
Plugin Name: Trigger GitHub Action Plugin
Plugin URI: https://github.com/thomasgauvin/trigger-github-action-plugin
Description: This plugin can trigger a GitHub Action job when a post or page is created or published.
Author: Thomas Gauvin
Version: 1.0.0
Author URI: https://thomasgauvin.com
License: MIT
Text Domain: trigger-github-action-plugin
*/


if(!defined('ABSPATH')) {
    exit;
}

class TriggerGithubActionPlugin 
{

    public $plugin_name;
    public $plugin_slug;

    function __construct()
    {
        $this->plugin_name = plugin_basename(__FILE__);
        $this->plugin_slug = 'trigger_github_action_plugin';
        $this->register();
    }

    function register()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('save_post', array($this, 'trigger_all_github_actions_workflows'), 10, 3);
        add_action('delete_post', array($this, 'trigger_all_github_actions_workflows'), 10, 3);
        add_filter("plugin_action_links_$this->plugin_name", array($this, 'add_action_links'));
    }

    function add_admin_menu()
    {
        add_menu_page(
            $this->plugin_name,
            'Trigger GitHub Action',
            'manage_options',
            $this->plugin_slug,
            array($this, 'admin_page')
        );
    }

    function trigger_all_github_actions_workflows($post_id, $post, $update)
    {
        //if draft, revision, or auto-save, do nothing
        //trigger site update if 'published' change or deletion ('trash')
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || ($post->post_status != 'publish' && $post->post_status != 'trash')) {
            return;
        }

        $repo_url = get_option('github_repo_url');
        $access_token = get_option('github_personal_access_token');
        $decrypted_gh_personal_access_token = openssl_decrypt($access_token, 'aes-256-cbc', $this->get_encryption_key(), 0, substr($this->get_salt(), 0, 16));

        try{
            $github_api_client = new GitHubAPIClient($repo_url, $decrypted_gh_personal_access_token);
            $github_api_client->trigger_all_gh_actions();
        }
        catch(Exception $e){
            error_log('There was an error when attempting to trigger the GitHub Action. Please configure the plugin settings correctly and try again.');
        }
    }

    function add_action_links($links)
    {
        $settings_link = array(
            '<a href="' . admin_url("admin.php?page=$this->plugin_slug") . '">Settings</a>',
        );
        return array_merge($links, $settings_link);
    }

    function admin_page()
    {
        $decrypted_gh_personal_access_token = openssl_decrypt(get_option('github_personal_access_token'), 'aes-256-cbc', $this->get_encryption_key(), 0, substr($this->get_salt(),0,16));

        if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['validate_action'])) {
            $repo_url = sanitize_text_field($_POST['repo_url']);
            $access_token = sanitize_text_field($_POST['access_token']);
            
            //encrypt the GitHub Personal Access Token

            $encrypted_gh_personal_access_token = openssl_encrypt($access_token, 'aes-256-cbc', $this->get_encryption_key(), 0, substr($this->get_salt(), 0, 16));

            // Store the data in WordPress options
            update_option('github_repo_url', $repo_url);
            update_option('github_personal_access_token', $encrypted_gh_personal_access_token);
            
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        ?>
                <div class="wrap">
            <h2>Trigger GitHub Action Settings</h2>
            <br />
            <form method="post" action="">
                <label for="repo_url">GitHub Repository URL:</label>
                <input type="text" name="repo_url" id="repo_url" value="<?php echo esc_attr(get_option('github_repo_url')); ?>" />

                <br><br>
                <label for="access_token">GitHub Personal Access Token:</label>
                <input type="password" name="access_token" id="access_token" value='<?php echo "" == $decrypted_gh_personal_access_token ? "" : "*********"  ?>' />

                <br><br>

                <input type="submit" class="button-primary" value="Save Settings" />
            </form>
            <br />
            <form method="post" action="">
                <input type="hidden" name="validate_action" value="true">
                <input type="submit" class="button" value="Manual trigger (validate configuration)">
            </form>
        </div>
        <?php


        if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['validate_action'])){

            //if repo url does not match github.com/owner/repo, return
            if(!preg_match('/^https?:\/\/github.com\/[a-zA-Z0-9-]+\/[a-zA-Z0-9-]+$/', get_option('github_repo_url'))){
                echo '<div class="error"><p>GitHub Repository URL is invalid. Please check your settings and try again.</p></div>';
                return;
            }

            $github_api_client = new GitHubAPIClient(get_option('github_repo_url'), $decrypted_gh_personal_access_token);
            $response = $github_api_client->get_all_workflows();

            if($response['response']['code'] == 200){
                echo '<div class="updated"><p>GitHub Personal Access Token is valid.</p></div>';
            } else {
                echo '<div class="error"><p>GitHub Personal Access Token is invalid. Please check your settings and try again.</p></div>';
            }

            //if response body workflows does not exist, return
            if(!array_key_exists('workflows', json_decode($response['body'], true))){
                return;
            }

            $workflows = json_decode($response['body'], true)['workflows'];

            //iterate through workflows and display them
            echo '<h2>Workflows</h2>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>Workflow Name</th><th>Workflow ID</th><th>Status</th><th>Message</th></tr></thead>';
            echo '<tbody>';
            foreach($workflows as $workflow){
                $response = $github_api_client->trigger_github_action($workflow['id']);
                if($response['response']['code'] == 204){
                    echo '<tr><td>' . $workflow['name'] . '</td><td>' . $workflow['id'] . '</td><td>Success</td><td>Workflow triggered successfully.</td></tr>';                 
                } else {
                    //display the response message
                    echo '<tr><td>' . $workflow['name'] . '</td><td>' . $workflow['id'] . '</td><td>Error</td><td>' . json_encode($response['body']) . '</td></tr>';
                }
            }
        }
    }

    function get_encryption_key(){
        if(defined('TRIGGER_GITHUB_ACTION_ENCRYPTION_KEY') && TRIGGER_GITHUB_ACTION_ENCRYPTION_KEY != ''){
            return TRIGGER_GITHUB_ACTION_ENCRYPTION_KEY;
        } elseif (defined('LOGGED_IN_KEY') && LOGGED_IN_KEY != '') {
            return LOGGED_IN_KEY;
        } else {
            // In the exceptional circumstance where the user has neither defined a custom encryption
            // key nor has a WordPress-generated LOGGED_IN_KEY in their config due to some error, 
            // we return a static string that provides no additional security. This is not ideal,
            // but a missing LOGGED_IN_KEY is indicative of much larger issues with this WordPress site.
            // If these WordPress-required keys https://api.wordpress.org/secret-key/1.1/salt/ are missing
            // from the config file, the administrator should immediately rectify this and update the keys.
            return 'unexpected-missing-encryption-key';
        }
    }

    function get_salt(){
        if(defined('TRIGGER_GITHUB_ACTION_SALT') && TRIGGER_GITHUB_ACTION_SALT != ''){
            return TRIGGER_GITHUB_ACTION_SALT;
        } elseif(defined('LOGGED_IN_SALT') && LOGGED_IN_SALT != ''){
            return LOGGED_IN_SALT;
        } else {
            // In the exceptional circumstance where the user has neither defined a custom salt nor
            // has a WordPress-generated LOGGED_IN_SALT in their config due to some error, 
            // we return a static string that provides no additional security. This is not ideal,
            // but a missing LOGGED_IN_SALT is indicative of much larger issues with this WordPress site.
            // If these WordPress-required keys https://api.wordpress.org/secret-key/1.1/salt/ are missing
            // from the config file, the administrator should immediately rectify this and update the keys.
            return 'unexpected-missing-salt';
        }
    }
}

if(class_exists('TriggerGithubActionPlugin')){
    $triggerGithubActionPlugin = new TriggerGithubActionPlugin();
}

    
