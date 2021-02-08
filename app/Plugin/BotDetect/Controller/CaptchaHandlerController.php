<?php

class CaptchaHandlerController extends BotDetectAppController {

    private $captcha;

    public function beforeFilter() {
        if ($this->isGetResourceContentsRequest()) {
            // validate filename
            $filename = $this->request->query['get'];
            if (!preg_match('/^[a-z-]+\.(css|gif|js)$/', $filename)) {
                $this->badRequest('Invalid file name.');
            }
        } else {
            // validate captcha id and load Captcha component
            $captchaId = $this->request->query['c'];
            if (is_null($captchaId) || !preg_match('/^(\w+)$/ui', $captchaId)) {
                $this->badRequest('Invalid captcha id.');
            }

            $captchaInstanceId = $this->request->query['t'];
            if (is_null($captchaInstanceId) || !(32 == strlen($captchaInstanceId) &&
                    (1 === preg_match("/^([a-f0-9]+)$/u", $captchaInstanceId)))) {
                $this->badRequest('Invalid instance id.');
            }

            $this->captcha = $this->Components->load('BotDetect.Captcha', [
                'captchaConfig' => $captchaId,
                'captchaInstanceId' => $captchaInstanceId
            ]);
        }

        parent::beforeFilter();

        if (method_exists('AuthComponent', 'allow')) {
            $this->Auth->allow('index');
        }
    }

    /**
     * Get the captcha image, sound, result validate, and file contents.
     */
    public function index() {

        if ($this->isGetResourceContentsRequest()) {
            // getting contents of css, js, and gif files.
            $this->getResourceContents();
        } else {
            // getting captcha image, sound, validation result
            $commandString = $this->request->query['get'];
            if (!BDC_StringHelper::HasValue($commandString)) {
                BDC_HttpHelper::BadRequest('command');
            }

            $commandString = BDC_StringHelper::Normalize($commandString);
            $command = BDC_CaptchaHttpCommand::FromQuerystring($commandString);
            $responseBody = '';
            switch ($command) {
                case BDC_CaptchaHttpCommand::GetImage:
                    $responseBody = $this->getImage();
                    break;
                case BDC_CaptchaHttpCommand::GetSound:
                    $responseBody = $this->getSound();
                    break;
                case BDC_CaptchaHttpCommand::GetValidationResult:
                    $responseBody = $this->getValidationResult();
                    break;
                case \BDC_CaptchaHttpCommand::GetScriptInclude:
                    $responseBody = $this->getScriptInclude();
                    break;
                case \BDC_CaptchaHttpCommand::GetP:
                    $responseBody = $this->getP();
                    break;
                default:
                    BDC_HttpHelper::BadRequest('command');
                    break;
            }

            // disallow audio file search engine indexing
            header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

            $this->autoRender = false;
            $this->response->disableCache();
            $this->response->body($responseBody);
        }
    }

    public function getResourceContents() {
        $filename = $this->request->query['get'];

        $this->viewClass = 'Media';

        $fileInfo  = pathinfo($filename);
        if (!(is_array($fileInfo) && array_key_exists('filename', $fileInfo) && array_key_exists('extension', $fileInfo))) {
            $this->badRequest(sprintf('The "%s" file could not be found.', $filename));
        }

        $params = array(
            'id'        => $filename,
            'name'      => $fileInfo['filename'],
            'download'  => false,
            'extension' => $fileInfo['extension']
        );

        $resourcePath = $this->getResourcePath();
        if (!is_null($resourcePath)) {
             $params['path'] = $resourcePath;
             $this->set($params);
        }
    }

    private function getResourcePath() {
        $innerAppDirResource = APP . 'Lib' . DS . 'botdetect' . DS .'public/';
        if (is_dir($innerAppDirResource)) {
            return $innerAppDirResource;
        }

        $outerResource_1 = ROOT . '/../botdetect-captcha-lib/botdetect/public/';
        if (is_dir($outerResource_1)) {
            return $outerResource_1;
        }

        $outerResource_2 = ROOT . '/../lib/botdetect/public/';
        if (is_dir($outerResource_2)) {
            return $outerResource_2;
        }

        $innerRootDirResource_1 = ROOT . '/botdetect-captcha-lib' . DS . 'botdetect' . DS . 'public/';
        if (is_dir($innerRootDirResource_1)) {
            return $innerRootDirResource_1;
        }

        $innerRootDirResource_2 = ROOT . '/lib' . DS . 'botdetect' . DS . 'public/';
        if (is_dir($innerRootDirResource_2)) {
            return $innerRootDirResource_2;
        }

        return null;
    }

    /**
     * Get captcha image.
     *
     * @return image
     */
    public function getImage() {

        if (is_null($this->captcha)) {
            BDC_HttpHelper::BadRequest('captcha');
        }

        // identifier of the particular Captcha object instance
        $instanceId = $this->getInstanceId();
        if (is_null($instanceId)) {
            BDC_HttpHelper::BadRequest('instance');
        }

        // image generation invalidates sound cache, if any
        $this->clearSoundData($instanceId);

        // response headers
        BDC_HttpHelper::DisallowCache();

        // response MIME type & headers
        $mimeType = $this->captcha->CaptchaBase->ImageMimeType;
        $this->response->type($mimeType);

        // we don't support content chunking, since image files
        // are regenerated randomly on each request
        header('Accept-Ranges: none');

        // image generation
        $rawImage = $this->captcha->CaptchaBase->GetImage($instanceId);
        $this->captcha->CaptchaBase->SaveCodeCollection(); // record generated Captcha code for validation

        // output image bytes
        $length = strlen($rawImage);
        header("Content-Length: {$length}");
        return $rawImage;
    }

    /**
     * Get captcha sound.
     *
     */
    public function getSound() {

        if (is_null($this->captcha)) {
            BDC_HttpHelper::BadRequest('captcha');
        }

        // identifier of the particular Captcha object instance
        $instanceId = $this->getInstanceId();
        if (is_null($instanceId)) {
            BDC_HttpHelper::BadRequest('instance');
        }

        $soundBytes = $this->getSoundData($this->captcha, $instanceId);

        if (is_null($soundBytes)) {
            \BDC_HttpHelper::BadRequest('Please reload the form page before requesting another Captcha sound');
            exit;
        }

        $totalSize = strlen($soundBytes);

        // response headers
        BDC_HttpHelper::SmartDisallowCache();

        // response MIME type & headers
        $mimeType = $this->captcha->CaptchaBase->SoundMimeType;
        $this->response->type($mimeType);
        header('Content-Transfer-Encoding: binary');

        if (!array_key_exists('d', $_GET)) { // javascript player not used, we send the file directly as a download
            $downloadId = \BDC_CryptoHelper::GenerateGuid();
            header("Content-Disposition: attachment; filename=captcha_{$downloadId}.wav");
        }

        if ($this->detectIosRangeRequest()) { // iPhone/iPad sound issues workaround: chunked response for iOS clients
            // sound byte subset
            $range = $this->getSoundByteRange();
            $rangeStart = $range['start'];
            $rangeEnd = $range['end'];
            $rangeSize = $rangeEnd - $rangeStart + 1;

            // initial iOS 6.0.1 testing; leaving as fallback since we can't be sure it won't happen again:
            // we depend on observed behavior of invalid range requests to detect
            // end of sound playback, cleanup and tell AppleCoreMedia to stop requesting
            // invalid "bytes=rangeEnd-rangeEnd" ranges in an infinite(?) loop
            if ($rangeStart == $rangeEnd || $rangeEnd > $totalSize) {
                \BDC_HttpHelper::BadRequest('invalid byte range');
            }

            $rangeBytes = substr($soundBytes, $rangeStart, $rangeSize);

            // partial content response with the requested byte range
            header('HTTP/1.1 206 Partial Content');
            header('Accept-Ranges: bytes');
            header("Content-Length: {$rangeSize}");
            header("Content-Range: bytes {$rangeStart}-{$rangeEnd}/{$totalSize}");
            return $rangeBytes; // chrome needs this kind of response to be able to replay Html5 audio
        } else if ($this->detectFakeRangeRequest()) {
            header('Accept-Ranges: bytes');
            header("Content-Length: {$totalSize}");
            $end = $totalSize - 1;
            header("Content-Range: bytes 0-{$end}/{$totalSize}");
            return $soundBytes;
        } else { // regular sound request
            header('Accept-Ranges: none');
            header("Content-Length: {$totalSize}");
            return $soundBytes;
        }
    }


    public function getSoundData($p_Captcha, $p_InstanceId) {
        $shouldCache = (
            ($p_Captcha->SoundRegenerationMode == \SoundRegenerationMode::None) || // no sound regeneration allowed, so we must cache the first and only generated sound
            $this->detectIosRangeRequest() // keep the same Captcha sound across all chunked iOS requests
        );

        if ($shouldCache) {
            $loaded = $this->loadSoundData($p_InstanceId);
            if (!is_null($loaded)) {
                return $loaded;
            }
        } else {
            $this->clearSoundData($p_InstanceId);
        }

        $soundBytes = $this->generateSoundData($p_Captcha, $p_InstanceId);
        if ($shouldCache) {
            $this->saveSoundData($p_InstanceId, $soundBytes);
        }
        return $soundBytes;
    }

    private function generateSoundData($p_Captcha, $p_InstanceId) {
        $rawSound = $p_Captcha->CaptchaBase->GetSound($p_InstanceId);
        $p_Captcha->CaptchaBase->SaveCodeCollection(); // always record sound generation count
        return $rawSound;
    }

    private function saveSoundData($p_InstanceId, $p_SoundBytes) {
        CAKE_Session_Save("BDC_Cached_SoundData_" . $p_InstanceId, $p_SoundBytes);
    }

    private function loadSoundData($p_InstanceId) {
        return CAKE_Session_Load("BDC_Cached_SoundData_" . $p_InstanceId);
    }

    private function clearSoundData($p_InstanceId) {
        CAKE_Session_Clear("BDC_Cached_SoundData_" . $p_InstanceId);
    }


    // Instead of relying on unreliable user agent checks, we detect the iOS sound
    // requests by the Http headers they will always contain
    private function detectIosRangeRequest() {
        if (array_key_exists('HTTP_RANGE', $_SERVER) &&
            \BDC_StringHelper::HasValue($_SERVER['HTTP_RANGE'])) {

            // Safari on MacOS and all browsers on <= iOS 10.x
            if (array_key_exists('HTTP_X_PLAYBACK_SESSION_ID', $_SERVER) &&
                \BDC_StringHelper::HasValue($_SERVER['HTTP_X_PLAYBACK_SESSION_ID'])) {
                return true;
            }

            $userAgent = array_key_exists('HTTP_USER_AGENT', $_SERVER) ? $_SERVER['HTTP_USER_AGENT'] : null;

            // all browsers on iOS 11.x and later
            if (\BDC_StringHelper::HasValue($userAgent)) {
		      	$userAgentLC = \BDC_StringHelper::Lowercase($userAgent);
	      		if (\BDC_StringHelper::Contains($userAgentLC, "like mac os") || \BDC_StringHelper::Contains($userAgentLC, "like macos")) {
		        	return true;
		      	}
		    }
        }
        return false;
    }

    private function getSoundByteRange() {
        // chunked requests must include the desired byte range
        $rangeStr = $_SERVER['HTTP_RANGE'];
        if (!\BDC_StringHelper::HasValue($rangeStr)) {
            return;
        }

        $matches = array();
        preg_match_all('/bytes=([0-9]+)-([0-9]+)/', $rangeStr, $matches);
        return array(
            'start' => (int) $matches[1][0],
            'end'   => (int) $matches[2][0]
        );
    }

    private function detectFakeRangeRequest() {
        $detected = false;
        if (array_key_exists('HTTP_RANGE', $_SERVER)) {
            $rangeStr = $_SERVER['HTTP_RANGE'];
            if (\BDC_StringHelper::HasValue($rangeStr) &&
                preg_match('/bytes=0-$/', $rangeStr)) {
                $detected = true;
            }
        }
        return $detected;
    }

    /**
     * The client requests the Captcha validation result (used for Ajax Captcha validation).
     *
     * @return json
     */
    public function getValidationResult() {

        if (is_null($this->captcha)) {
            BDC_HttpHelper::BadRequest('captcha');
        }

        // identifier of the particular Captcha object instance
        $instanceId = $this->getInstanceId();
        if (is_null($instanceId)) {
            BDC_HttpHelper::BadRequest('instance');
        }

        $mimeType = 'application/json';
        $this->response->type($mimeType);

        // code to validate
        $userInput = $this->getUserInput();

        // JSON-encoded validation result
        $result = false;
        if (isset($userInput) && (isset($instanceId))) {
            $result = $this->captcha->AjaxValidate($userInput, $instanceId);
            $this->captcha->CaptchaBase->SaveCodeCollection();
        }
        $resultJson = $this->getJsonValidationResult($result);

        return $resultJson;
    }

    public function getScriptInclude() {
        // saved data for the specified Captcha object in the application
        if (is_null($this->captcha)) {
            \BDC_HttpHelper::BadRequest('captcha');
        }

        // identifier of the particular Captcha object instance
        $instanceId = $this->getInstanceId();
        if (is_null($instanceId)) {
            \BDC_HttpHelper::BadRequest('instance');
        }

        // response MIME type & headers
        header('Content-Type: text/javascript');
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

        // 1. load BotDetect script
        $resourcePath = $this->getResourcePath();

        if (is_null($resourcePath)) {
            \BDC_HttpHelper::BadRequest('Resource folder could not be found.');
        }

        $filePath = $resourcePath . 'bdc-traditional-api-script-include.js';

        if (!is_file($filePath)) {
            \BDC_HttpHelper::BadRequest(sprintf('File "%s" could not be found.', $filePath));
        }

        $script = file_get_contents($filePath);

        // 2. load BotDetect Init script
        $script .= \BDC_CaptchaScriptsHelper::GetInitScriptMarkup($this->captcha, $instanceId);

        // 3. load remote scripts if enabled
        if ($this->captcha->RemoteScriptEnabled) {
            $script .= "\r\n";
            $script .= \BDC_CaptchaScriptsHelper::GetRemoteScript($this->captcha);
        }

        return $script;
    }

    /**
     * @return string
     */
    private function getInstanceId() {
        $instanceId = $this->request->query['t'];
        if (!BDC_StringHelper::HasValue($instanceId) ||
            !BDC_CaptchaBase::IsValidInstanceId($instanceId)) {
            return;
        }
        return $instanceId;
    }

    /**
     * Extract the user input Captcha code string from the Ajax validation request.
     *
     * @return string
     */
    private function getUserInput() {
        $input = null;

        if (isset($this->request->query['i'])) {
            // BotDetect built-in Ajax Captcha validation
            $input = $this->request->query['i'];
        } else {
            // jQuery validation support, the input key may be just about anything,
            // so we have to loop through fields and take the first unrecognized one
            $recognized = array('get', 'c', 't', 'd');
            foreach($this->request->query as $key => $value) {
                if (!in_array($key, $recognized)) {
                    $input = $value;
                    break;
                }
            }
        }

        return $input;
    }

    /**
     * Encodes the Captcha validation result in a simple JSON wrapper.
     *
     * @return string
     */
    private function getJsonValidationResult($result) {
        $resultStr = ($result ? 'true': 'false');
        return $resultStr;
    }

    /**
     * @return bool
     */
    private function isGetResourceContentsRequest() {
        $http_get_data = $this->request->query;
        return array_key_exists('get', $http_get_data) && !array_key_exists('c', $http_get_data);
    }

    /**
     * Throw an error header.
     *
     * @param string  $message
     * @return void
     */
    private function badRequest($message) {
        while (ob_get_contents()) { ob_end_clean(); }
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: text/plain');
        echo $message;
        exit;
    }

    public function getP() {
        if (is_null($this->captcha)) {
            \BDC_HttpHelper::BadRequest('captcha');
        }

        // identifier of the particular Captcha object instance
        $instanceId = $this->getInstanceId();
        if (is_null($instanceId)) {
            \BDC_HttpHelper::BadRequest('instance');
        }

        // create new one
        $p = $this->captcha->GenPw($instanceId);
        $this->captcha->SavePw($this->captcha);

        // response data
        $response = "{\"sp\":\"{$p->GetSP()}\",\"hs\":\"{$p->GetHs()}\"}";

        // response MIME type & headers
        header('Content-Type: application/json');
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
        \BDC_HttpHelper::SmartDisallowCache();

        return $response;
    }
}