<?php namespace ORTInteractive\ContaoNewsletter;

use Contao\ModuleSubscribe as NewsletterSubscribe;

class ModuleSubscribe extends NewsletterSubscribe
{

    /**
     * Add a new recipient
     */
    protected function addRecipient()
    {
        $arrChannels = \Input::post('channels');

        if (!is_array($arrChannels))
        {
            $_SESSION['UNSUBSCRIBE_ERROR'] = $GLOBALS['TL_LANG']['ERR']['noChannels'];
            $this->reload();
        }

        $arrChannels = array_intersect($arrChannels, $this->nl_channels); // see #3240

        // Check the selection
        if (!is_array($arrChannels) || empty($arrChannels))
        {
            $_SESSION['SUBSCRIBE_ERROR'] = $GLOBALS['TL_LANG']['ERR']['noChannels'];
            $this->reload();
        }

        $varInput = \Idna::encodeEmail(\Input::post('email', true));

        // Validate the e-mail address
        if (!\Validator::isEmail($varInput))
        {
            $_SESSION['SUBSCRIBE_ERROR'] = $GLOBALS['TL_LANG']['ERR']['email'];
            $this->reload();
        }

        $arrSubscriptions = array();

        // Get the existing active subscriptions
        if (($objSubscription = \NewsletterRecipientsModel::findBy(array("email=? AND active=1"), $varInput)) !== null)
        {
            $arrSubscriptions = $objSubscription->fetchEach('pid');
        }

        $arrNew = array_diff($arrChannels, $arrSubscriptions);

        // Return if there are no new subscriptions
        if (!is_array($arrNew) || empty($arrNew))
        {
            $_SESSION['SUBSCRIBE_ERROR'] = $GLOBALS['TL_LANG']['ERR']['subscribed'];
            $this->reload();
        }

        // Remove old subscriptions that have not been activated yet
        if (($objOld = \NewsletterRecipientsModel::findBy(array("email=? AND active=''"), $varInput)) !== null)
        {
            while ($objOld->next())
            {
                $objOld->delete();
            }
        }

        $time = time();
        $strToken = md5(uniqid(mt_rand(), true));

        // Add the new subscriptions
        foreach ($arrNew as $id)
        {
            $objRecipient = new \NewsletterRecipientsModel();

            $objRecipient->pid = $id;
            $objRecipient->tstamp = $time;
            $objRecipient->email = $varInput;
            $objRecipient->active = '';
            $objRecipient->addedOn = $time;
            $objRecipient->ip = $this->anonymizeIp(\Environment::get('ip'));
            $objRecipient->token = $strToken;
            $objRecipient->confirmed = '';

            $objRecipient->save();
        }

        // Get the channels
        $objChannel = \NewsletterChannelModel::findByIds($arrChannels);

        // Prepare the simple token data
        $arrData = array();
        $arrData['token'] = $strToken;
        $arrData['domain'] = \Idna::decode(\Environment::get('host'));
        $arrData['link'] = \Idna::decode(\Environment::get('base')) . \Environment::get('request') . ((\Config::get('disableAlias') || strpos(\Environment::get('request'), '?') !== false) ? '&' : '?') . 'token=' . $strToken;
        $arrData['channel'] = $arrData['channels'] = implode("\n", $objChannel->fetchEach('title'));

        // Activation e-mail
        $objTemplate = new \BackendTemplate( "mail_default" );
        $objTemplate->mailTemplate = "nl_subscribe";
        $objTemplate->content = \StringUtil::parseSimpleTokens( $this->nl_subscribe, $arrData );
        $objTemplate->link = \StringUtil::parseSimpleTokens( "##link##", $arrData );

        // Send e-mail
        $objEmail = new \Email( );
        $objEmail->html = $objTemplate->parse( );
        $objEmail->from = $GLOBALS["TL_ADMIN_EMAIL"];
        $objEmail->fromName = $GLOBALS["TL_ADMIN_NAME"];
        $objEmail->subject = sprintf( $GLOBALS['TL_LANG']['MSC']['nl_subject'], \Idna::decode( \Environment::get( 'host' ) ) );
        $objEmail->imageDir = TL_ROOT . '/';
        $objEmail->sendTo( $varInput );

        // Redirect to the jumpTo page
        if ($this->jumpTo && ($objTarget = $this->objModel->getRelated('jumpTo')) !== null)
        {
            $this->redirect($this->generateFrontendUrl($objTarget->row()));
        }

        $_SESSION['SUBSCRIBE_CONFIRM'] = $GLOBALS['TL_LANG']['MSC']['nl_confirm'];
        $this->reload();
    }

}