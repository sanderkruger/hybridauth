<?php
use Facebook\Exceptions\FacebookSDKException;
use Facebook\Facebook as FacebookSDK;

/*
 * !
 * HybridAuth
 * http://hybridauth.sourceforge.net | http://github.com/hybridauth/hybridauth
 * (c) 2009-2012, HybridAuth authors | http://hybridauth.sourceforge.net/licenses.html
 */

/**
 * Hybrid_Providers_Facebook provider adapter based on OAuth2 protocol
 * Hybrid_Providers_Facebook use the Facebook PHP SDK created by Facebook
 * http://hybridauth.sourceforge.net/userguide/IDProvider_info_Facebook.html
 */
class Hybrid_Providers_Facebook extends Hybrid_Provider_Model
{

    /**
     * Default permissions, and a lot of them.
     * You can change them from the configuration by setting the scope to what you want/need.
     * For a complete list see: https://developers.facebook.com/docs/facebook-login/permissions
     *
     * @link https://developers.facebook.com/docs/facebook-login/permissions
     * @var array $scope
     */
    public $scope = [
        'email',
        'user_about_me',
        'user_birthday',
        'user_hometown',
        'user_location',
        'user_website',
        'publish_actions',
        'read_custom_friendlists'
    ];

    /**
     * Provider API client
     *
     * @var \Facebook\Facebook
     */
    public $api;

    public $useSafeUrls = true;

    /**
     *
     * {@inheritdoc}
     */
    function initialize()
    {
        if (! $this->config["keys"]["id"] || ! $this->config["keys"]["secret"]) {
            throw new Exception("Your application id and secret are required in order to connect to {$this->providerId}.", 4);
        }
        
        if (isset($this->config['scope'])) {
            $scope = $this->config['scope'];
            if (is_string($scope)) {
                $scope = explode(",", $scope);
            }
            $scope = array_map('trim', $scope);
            $this->scope = $scope;
        }
        
        $trustForwarded = isset($this->config['trustForwarded']) ? (bool) $this->config['trustForwarded'] : false;
        
        // Check if there is Graph SDK in thirdparty/Facebook.
        if (file_exists(Hybrid_Auth::$config["path_libraries"] . "Facebook/autoload.php")) {
            require_once Hybrid_Auth::$config["path_libraries"] . "Facebook/autoload.php";
        } else {
            // If Composer install was executed, try to find autoload.php.
            $vendorDir = dirname(Hybrid_Auth::$config['path_base']);
            do {
                if (file_exists($vendorDir . "/vendor/autoload.php")) {
                    require_once $vendorDir . "/vendor/autoload.php";
                    break;
                }
            } while (($vendorDir = dirname($vendorDir)) !== '/');
        }
        
        $this->api = new FacebookSDK([
            'app_id' => $this->config["keys"]["id"],
            'app_secret' => $this->config["keys"]["secret"],
            'default_graph_version' => 'v2.8',
            'trustForwarded' => $trustForwarded
        ]);
    }

    /**
     *
     * {@inheritdoc}
     */
    function loginBegin()
    {
        $this->endpoint = $this->params['login_done'];
        $helper = $this->api->getRedirectLoginHelper();
        
        // Use re-request, because this will trigger permissions window if not all permissions are granted.
        $url = $helper->getReRequestUrl($this->endpoint, $this->scope);
        
        // Redirect to Facebook
        Hybrid_Auth::redirect($url);
    }

    /**
     *
     * {@inheritdoc}
     */
    function loginFinish()
    {
        $helper = $this->api->getRedirectLoginHelper();
        try {
            $accessToken = $helper->getAccessToken($this->params['login_done']);
        } catch (Facebook\Exceptions\FacebookResponseException $e) {
            // throw new Hybrid_Exception('Facebook Graph returned an error: ' . $e->getMessage());
            
            // Application request limit reached
            return;
        } catch (Facebook\Exceptions\FacebookSDKException $e) {
            // throw new Hybrid_Exception('Facebook SDK returned an error: ' . $e->getMessage());
            
            // Application request limit reached
            return;
        }
        
        if (! isset($accessToken)) {
            if ($helper->getError()) {
                // throw new Hybrid_Exception(sprintf("Could not authorize user, reason: %s (%d)", $helper->getErrorDescription(), $helper->getErrorCode()));
                
                // Application request limit reached
                return;
            } else {
                // throw new Hybrid_Exception("Could not authorize user. Bad request");
                
                // Application request limit reached
                return;
            }
        }
        
        try {
            // Validate token
            $oAuth2Client = $this->api->getOAuth2Client();
            $tokenMetadata = $oAuth2Client->debugToken($accessToken);
            $tokenMetadata->validateAppId($this->config["keys"]["id"]);
            $tokenMetadata->validateExpiration();
            
            // Exchanges a short-lived access token for a long-lived one
            if (! $accessToken->isLongLived()) {
                $accessToken = $oAuth2Client->getLongLivedAccessToken($accessToken);
            }
        } catch (FacebookSDKException $e) {
            // throw new Hybrid_Exception($e->getMessage(), 0, $e);
            
            // Application request limit reached
            return;
        }
        
        $this->setUserConnected();
        $this->token("access_token", $accessToken->getValue());
    }

    /**
     *
     * {@inheritdoc}
     */
    function logout()
    {
        parent::logout();
    }

    /**
     * Update user status
     *
     * @param mixed $status
     *            An array describing the status, or string
     * @param string $pageid
     *            (optional) User page id
     * @return array
     * @throw Exception
     */
    function setUserStatus($status, $pageid = null)
    {
        if (! is_array($status)) {
            $status = array(
                'message' => $status
            );
        }
        
        $access_token = null;
        
        if (is_null($pageid)) {
            $pageid = 'me';
            $access_token = $this->token('access_token');
            
            // if post on page, get access_token page
        } else {
            
            foreach ($this->getUserPages(true) as $p) {
                if (isset($p['id']) && intval($p['id']) == intval($pageid)) {
                    $access_token = $p['access_token'];
                    break;
                }
            }
            
            if (is_null($access_token)) {
                throw new Exception("Update user page failed, page not found or not writable!");
            }
        }
        
        try {
            $response = $this->api->post('/' . $pageid . '/feed', $status, $access_token);
        } catch (FacebookSDKException $e) {
            // throw new Exception("Update user status failed! {$this->providerId} returned an error {$e->getMessage()}", 0, $e);
            
            // Application request limit reached
            return;
        }
        
        return $response;
    }

    /**
     * {@inheridoc}
     */
    function getUserPages($writableonly = false)
    {
        if ((isset($this->config['scope']) && strpos($this->config['scope'], 'manage_pages') === false) || (! isset($this->config['scope']) && strpos($this->scope, 'manage_pages') === false))
            throw new Exception("User status requires manage_page permission!");
        
        try {
            $pages = $this->api->get("/me/accounts", $this->token('access_token'));
            $pages = $pages->getDecodedBody();
        } catch (FacebookApiException $e) {
            // throw new Exception("Cannot retrieve user pages! {$this->providerId} returned an error: {$e->getMessage()}", 0, $e);
            
            // Application request limit reached
            return;
        }
        
        if (! isset($pages['data'])) {
            return array();
        }
        
        if (! $writableonly) {
            return $pages['data'];
        }
        
        $wrpages = array();
        foreach ($pages['data'] as $p) {
            if (isset($p['perms']) && in_array('CREATE_CONTENT', $p['perms'])) {
                $wrpages[] = $p;
            }
        }
        
        return $wrpages;
    }

    /**
     *
     * {@inheritdoc}
     */
    function getUserProfile()
    {
        try {
            $fields = [
                'id',
                'name',
                'first_name',
                'last_name',
                'link',
                'website',
                'gender',
                'locale',
                'about',
                'email',
                'hometown',
                'location',
                'birthday'
            ];
            $response = $this->api->get('/me?fields=' . implode(',', $fields), $this->token('access_token'));
            $data = $response->getDecodedBody();
        } catch (FacebookSDKException $e) {
            // throw new Exception("User profile request failed! {$this->providerId} returned an error: {$e->getMessage()}", 6, $e);
            
            // Application request limit reached
            return;
        }
        
        // Store the user profile.
        $this->user->profile->identifier = (array_key_exists('id', $data)) ? $data['id'] : "";
        $this->user->profile->displayName = (array_key_exists('name', $data)) ? $data['name'] : "";
        $this->user->profile->firstName = (array_key_exists('first_name', $data)) ? $data['first_name'] : "";
        $this->user->profile->lastName = (array_key_exists('last_name', $data)) ? $data['last_name'] : "";
        $this->user->profile->photoURL = ! empty($this->user->profile->identifier) ? "https://graph.facebook.com/" . $this->user->profile->identifier . "/picture?width=150&height=150" : '';
        $this->user->profile->profileURL = (array_key_exists('link', $data)) ? $data['link'] : "";
        $this->user->profile->webSiteURL = (array_key_exists('website', $data)) ? $data['website'] : "";
        $this->user->profile->gender = (array_key_exists('gender', $data)) ? $data['gender'] : "";
        $this->user->profile->language = (array_key_exists('locale', $data)) ? $data['locale'] : "";
        $this->user->profile->description = (array_key_exists('about', $data)) ? $data['about'] : "";
        $this->user->profile->email = (array_key_exists('email', $data)) ? $data['email'] : "";
        $this->user->profile->emailVerified = (array_key_exists('email', $data)) ? $data['email'] : "";
        $this->user->profile->region = (array_key_exists("location", $data) && array_key_exists("name", $data['location'])) ? $data['location']["name"] : "";
        
        if (! empty($this->user->profile->region)) {
            $regionArr = explode(',', $this->user->profile->region);
            if (count($regionArr) > 1) {
                $this->user->profile->city = trim($regionArr[0]);
                $this->user->profile->country = trim(end($regionArr));
            }
        }
        
        if (array_key_exists('birthday', $data)) {
            $birtydayPieces = explode('/', $data['birthday']);
            
            if (count($birtydayPieces) == 1) {
                $this->user->profile->birthYear = (int) $birtydayPieces[0];
            } elseif (count($birtydayPieces) == 2) {
                $this->user->profile->birthMonth = (int) $birtydayPieces[0];
                $this->user->profile->birthDay = (int) $birtydayPieces[1];
            } elseif (count($birtydayPieces) == 3) {
                $this->user->profile->birthMonth = (int) $birtydayPieces[0];
                $this->user->profile->birthDay = (int) $birtydayPieces[1];
                $this->user->profile->birthYear = (int) $birtydayPieces[2];
            }
        }
        
        return $this->user->profile;
    }

    /**
     * Since the Graph API 2.0, the /friends endpoint only returns friend that also use your Facebook app.
     *
     * {@inheritdoc}
     */
    function getUserContacts()
    {
        $apiCall = '?fields=link,name';
        $returnedContacts = [];
        $pagedList = true;
        
        while ($pagedList) {
            try {
                $response = $this->api->get('/me/friends' . $apiCall, $this->token('access_token'));
                $response = $response->getDecodedBody();
            } catch (FacebookSDKException $e) {
                // throw new Hybrid_Exception("User contacts request failed! {$this->providerId} returned an error {$e->getMessage()}", 0, $e);
                
                // Application request limit reached
                return;
            }
            
            // Prepare the next call if paging links have been returned
            if (array_key_exists('paging', $response) && array_key_exists('next', $response['paging'])) {
                $pagedList = true;
                $next_page = explode('friends', $response['paging']['next']);
                $apiCall = $next_page[1];
            } else {
                $pagedList = false;
            }
            
            // Add the new page contacts
            $returnedContacts = array_merge($returnedContacts, $response['data']);
        }
        
        $contacts = [];
        
        foreach ($returnedContacts as $item) {
            
            $uc = new Hybrid_User_Contact();
            $uc->identifier = (array_key_exists("id", $item)) ? $item["id"] : "";
            $uc->displayName = (array_key_exists("name", $item)) ? $item["name"] : "";
            $uc->profileURL = (array_key_exists("link", $item)) ? $item["link"] : "https://www.facebook.com/profile.php?id=" . $uc->identifier;
            $uc->photoURL = "https://graph.facebook.com/" . $uc->identifier . "/picture?width=150&height=150";
            
            $contacts[] = $uc;
        }
        
        return $contacts;
    }

    /**
     * Load the user latest activity, needs 'read_stream' permission
     *
     * @param string $stream
     *            Which activity to fetch:
     *            - timeline : all the stream
     *            - me : the user activity only
     * {@inheritdoc}
     */
    function getUserActivity($stream = 'timeline')
    {
        try {
            if ($stream == "me") {
                $response = $this->api->get('/me/feed', $this->token('access_token'));
            } else {
                $response = $this->api->get('/me/home', $this->token('access_token'));
            }
            $response = $response->getDecodedBody();
        } catch (FacebookSDKException $e) {
            // throw new Hybrid_Exception("User activity stream request failed! {$this->providerId} returned an error: {$e->getMessage()}", 0, $e);
            
            // Application request limit reached
            return;
        }
        
        if (! $response || ! count($response['data'])) {
            return [];
        }
        
        $activities = [];
        
        foreach ($response['data'] as $item) {
            
            $ua = new Hybrid_User_Activity();
            
            $ua->id = (array_key_exists("id", $item)) ? $item["id"] : "";
            $ua->date = (array_key_exists("created_time", $item)) ? strtotime($item["created_time"]) : "";
            
            if ($item["type"] == "video") {
                $ua->text = (array_key_exists("link", $item)) ? $item["link"] : "";
            }
            
            if ($item["type"] == "link") {
                $ua->text = (array_key_exists("link", $item)) ? $item["link"] : "";
            }
            
            if (empty($ua->text) && isset($item["story"])) {
                $ua->text = (array_key_exists("link", $item)) ? $item["link"] : "";
            }
            
            if (empty($ua->text) && isset($item["message"])) {
                $ua->text = (array_key_exists("message", $item)) ? $item["message"] : "";
            }
            
            if (! empty($ua->text)) {
                $ua->user->identifier = (array_key_exists("id", $item["from"])) ? $item["from"]["id"] : "";
                $ua->user->displayName = (array_key_exists("name", $item["from"])) ? $item["from"]["name"] : "";
                $ua->user->profileURL = "https://www.facebook.com/profile.php?id=" . $ua->user->identifier;
                $ua->user->photoURL = "https://graph.facebook.com/" . $ua->user->identifier . "/picture?type=square";
                
                $activities[] = $ua;
            }
        }
        
        return $activities;
    }

    function getUserPosts()
    {
        try {
            $fields = [
                'id',
                'message',
                'created_time',
                'full_picture',
                'story'
            ];
            $response = $this->api->get('/me/posts?fields=' . implode(',', $fields), $this->token('access_token'));
            $data = $response->getDecodedBody();
        } catch (FacebookSDKException $e) {
            // throw new Exception("Could not get user posts! {$this->providerId} returned an error: {$e->getMessage()}", 6, $e);
            
            // Application request limit reached
            return;
        }
        
        // Store the posts.
        if (array_key_exists('id', $data)) {
            $this->api->lastResponse->decodedBody->data->id = (array_key_exists('id', $data)) ? $data['id'] : "";
        }
        if (array_key_exists('created_time', $data)) {
            $this->api->lastResponse->decodedBody->data->created_time = (array_key_exists('created_time', $data)) ? $data['created_time'] : "";
        }
        if (array_key_exists('story', $data)) {
            $this->api->lastResponse->decodedBody->data->story = (array_key_exists('story', $data)) ? $data['story'] : "";
        }
        if (array_key_exists('full_picture', $data)) {
            $this->api->lastResponse->decodedBody->data->full_picture = (array_key_exists('full_picture', $data)) ? $data['full_picture'] : "";
        }
        if (array_key_exists('message', $data)) {
            $this->api->lastResponse->decodedBody->data->message = (array_key_exists('message', $data)) ? $data['message'] : "";
        }
        
        return $data;
    }

    function getPostsDetails($postID)
    {
        try {
            $response = $this->api->get('/' . $postID . '?fields=shares,likes.summary(true),comments.summary(true)', $this->token('access_token'));
            $data = $response->getDecodedBody();
        } catch (FacebookSDKException $e) {
            // throw new Exception("Could not get post details! {$this->providerId} returned an error: {$e->getMessage()}", 6, $e);
            
            // Application request limit reached
            return;
        }
        
        return $data;
    }

    function getFriendsCount()
    {
        try {
            $response = $this->api->get('/me/friends', $this->token('access_token'));
            $data = $response->getDecodedBody();
            
        } catch (FacebookSDKException $e) {
            // throw new Exception("Could not get friends count! {$this->providerId} returned an error: {$e->getMessage()}", 6, $e);
            
            // Application request limit reached
            return;
        }
        
        return $data;
    }
    
    function getPageId()
    {
        try {
            
            $accountsData = $this->api->get('/me/accounts', $this->token('access_token'));
            $accounts = $accountsData->getDecodedBody();
            $pageId = null;
            if (isset($accounts['data'][0])) {
                $pageId = $accounts['data'][0]['id'];
            }
            
        } catch (FacebookSDKException $e) {
            // throw new Exception("Could not get page id! {$this->providerId} returned an error: {$e->getMessage()}", 6, $e);
            
            // Application request limit reached
            return;
        }
        
        return $pageId;
    }
    
    function getPageAccessToken()
    {
        try {
            $pageId = $this->getPageId();
            $pageAccessTokenData = $this->api->get($pageId . '?fields=access_token', $this->token('access_token'));
            $pageAccessTokenArray = $pageAccessTokenData->getDecodedBody();
            $pageAccessToken = $pageAccessTokenArray['access_token'];
        } catch (FacebookSDKException $e) {
            // throw new Exception("Could not get Facebook Page Access Token! {$this->providerId} returned an error: {$e->getMessage()}", 6, $e);

            // Application request limit reached
            return;
        }
        
        return $pageAccessToken;
    }
    
    function getPageInsights()
    {
        try {
            $pageId = $this->getPageId();
            $pageAccessToken = $this->getPageAccessToken();
            $pageInsightsData = $this->api->get($pageId . '/insights?metric=page_views_total,page_fans_gender_age,page_fans_country', $pageAccessToken);
            $pageInsights = $pageInsightsData->getDecodedBody();
        } catch (FacebookSDKException $e) {
            // throw new Exception("Could not get Facebook Page Insights! {$this->providerId} returned an error: {$e->getMessage()}", 6, $e);
            
            // Application request limit reached
            return;
        }
        
        return $pageInsights;
    }
    
    function getPagePosts()
    {
        try {
            $pageId = $this->getPageId();
            $pagePostsData = $this->api->get($pageId . '/posts?fields=message,full_picture,likes,comments,shares,created_time', $this->token('access_token'));
            $pagePosts = $pagePostsData->getDecodedBody();
        } catch (FacebookSDKException $e) {
            // throw new Exception("Could not get Facebook Page Posts! {$this->providerId} returned an error: {$e->getMessage()}", 6, $e);
            
            // Application request limit reached
            return;
        }
        
        return $pagePosts;
    }
    
    function getPagePostInsights($postID)
    {
        try {
            $pageAccessToken = $this->getPageAccessToken();
            $pagePostsInsightsData = $this->api->get($postID . '/insights?metric=post_reactions_like_total,post_impressions_unique', $pageAccessToken);
            $pagePostInsights = $pagePostsInsightsData->getDecodedBody();
        } catch (FacebookSDKException $e) {
            // throw new Exception("Could not get Facebook Page Post Insights! {$this->providerId} returned an error: {$e->getMessage()}", 6, $e);
            
            // Application request limit reached
            return;
        }
        
        return $pagePostInsights;
    }
    
    function getInstagramBusinessAccountID()
    {
        try {
            $pageId = $this->getPageId();
            
            $instagramBusinessData = $this->api->get($pageId . '?fields=instagram_business_account', $this->token('access_token'));
            $instagramBusiness = $instagramBusinessData->getDecodedBody();
            $instagramBusinessAccountID = null;
            if (isset($instagramBusiness['instagram_business_account']['id'])) {
                $instagramBusinessAccountID = $instagramBusiness['instagram_business_account']['id'];
            }
        } catch (FacebookSDKException $e) {
            // throw new Exception("Could not get Instagram Business Account ID! {$this->providerId} returned an error: {$e->getMessage()}", 6, $e);
            
            // Application request limit reached
            return;
        }
        
        return $instagramBusinessAccountID;
    }
    
    function getInstagramBusinessAccountName($instagramBusinessAccountID)
    {
        try {
            $pageAccessToken = $this->getPageAccessToken();
            $instagramBusinessAccountName= $this->api->get($instagramBusinessAccountID . '?fields=name', $pageAccessToken);
            $instagramBusinessName = $instagramBusinessAccountName->getDecodedBody();
            $name = null;
            if (isset($instagramBusinessName['name'])) {
                if (!empty($instagramBusinessName['name'])) {
                    $name = $instagramBusinessName['name'];
                }
            }
        } catch (FacebookSDKException $e) {
            // throw new Exception("Could not get Instagram Business Account Name! {$this->providerId} returned an error: {$e->getMessage()}", 6, $e);
            
            // Application request limit reached
            return;
        }
        
        return $name;
    }
    
    function getInstagramInsights()
    {
        try {
            $instagramBusinessAccountID = $this->getInstagramBusinessAccountID();
            
            $genderAgeLocationData = $this->api->get($instagramBusinessAccountID . '/insights?metric=audience_gender_age,audience_country&period=lifetime', $this->token('access_token'));
            $genderAgeLocation = $genderAgeLocationData->getDecodedBody();
            
            $viewsData = $this->api->get($instagramBusinessAccountID . '/insights?metric=reach&period=day,week,days_28', $this->token('access_token'));
            $views = $viewsData->getDecodedBody();
            
            $instagramInsights = array();
            $instagramInsights['genderAgeLocation'] = $genderAgeLocation;
            $instagramInsights['views'] = $views;
        } catch (FacebookSDKException $e) {
            //throw new Exception("Could not get Instagram Business Account Insights! {$this->providerId} returned an error: {$e->getMessage()}", 6, $e);
            
            // Application request limit reached
            return;
        }
        
        return $instagramInsights;
    }
    
    function revokeAccess()
    {
        try {
            $params = [];
            $response = $this->api->delete('/me/permissions', $params, $this->token('access_token'));
            $data = $response->getDecodedBody();
        } catch (FacebookSDKException $e) {
            // throw new Exception("Could not revoke access! {$this->providerId} returned an error: {$e->getMessage()}", 6, $e);
            
            // Application request limit reached
            return;
        }
        
        return $data;
    }

}
