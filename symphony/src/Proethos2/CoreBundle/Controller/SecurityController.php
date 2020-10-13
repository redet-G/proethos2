<?php

// This file is part of the ProEthos Software. 
// 
// Copyright 2013, PAHO. All rights reserved. You can redistribute it and/or modify
// ProEthos under the terms of the ProEthos License as published by PAHO, which
// restricts commercial use of the Software. 
// 
// ProEthos is distributed in the hope that it will be useful, but WITHOUT ANY
// WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
// PARTICULAR PURPOSE. See the ProEthos License for more details. 
// 
// You should have received a copy of the ProEthos License along with the ProEthos
// Software. If not, see
// https://github.com/bireme/proethos2/blob/master/LICENSE.txt


namespace Proethos2\CoreBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

use TheNetworg\OAuth2\Client\Provider\Azure;

use Proethos2\ModelBundle\Entity\User;
use Proethos2\CoreBundle\Util\Util;

use Cocur\Slugify\Slugify;


class SecurityController extends Controller
{
    public function __construct()
    {
        global $kernel;
        $env_dir = dirname($kernel->getRootDir());

        // load enviroment variables
        $dotenv = new Dotenv();
        $dotenv->load($env_dir.'/.env');
    }

    /**
     * @Route("/login", name="login_route")
     * @Template()
     */
    public function loginAction(Request $request)
    {
        $util = new Util($this->container, $this->getDoctrine());
        
        $authenticationUtils = $this->get('security.authentication_utils');

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        $committee_name = $util->getConfiguration("committee.name");
        $committee_description = $util->getConfiguration("committee.description");
        $committee_logourl = $util->getConfiguration("committee.logourl");

        $auth_type = $this->container->getParameter('auth_type');

        $output = array(
            'last_username'         => $lastUsername,
            'error'                 => $error,
            'auth_type'             => $auth_type,
            'committee_name'        => $committee_name,
            'committee_description' => $committee_description,
            'committee_logourl'     => $committee_logourl,
        );

        if ( 'oauth2' == $auth_type ) {
            $router = $this->container->get('router');
            $redirect = $router->generate('login_route', array(), true);
            $azureClientId = ( $_ENV['AZURE_CLIENT_ID'] ) ? $_ENV['AZURE_CLIENT_ID'] : '';
            $azureClientSecret = ( $_ENV['AZURE_CLIENT_SECRET'] ) ? $_ENV['AZURE_CLIENT_SECRET'] : '';

            $provider = new Azure([
                'clientId'     => $azureClientId,
                'clientSecret' => $azureClientSecret,
                'redirectUri'  => $redirect
            ]);

            // Set to use v2 API, skip the line or set the value to Azure::ENDPOINT_VERSION_1_0 if willing to use v1 API
            $provider->defaultEndPointVersion = Azure::ENDPOINT_VERSION_2_0;

            $baseGraphUri = $provider->getRootMicrosoftGraphUri(null);
            $provider->scope = 'openid profile email offline_access ' . $baseGraphUri . '/User.Read';

            $oauth2_code = $request->query->get('code');
            $oauth2_state = $request->query->get('state');
            $session_state = $this->get('session')->get('OAuth2.state');

            if (isset($oauth2_code) && isset($session_state) && isset($oauth2_state)) {
                if ($oauth2_state == $session_state) {
                    $this->get('session')->remove('OAuth2.state');

                    // Try to get an access token (using the authorization code grant)
                    /** @var AccessToken $token */
                    $token = $provider->getAccessToken('authorization_code', [
                        'scope' => $provider->scope,
                        'code' => $_GET['code'],
                    ]);

                    // Verify token
                    //$token->getToken()
                    $data = $provider->get($provider->getRootMicrosoftGraphUri($token) . '/v1.0/me', $token);

                    if ( !$data ) {
                        $output['error'] = $translator->trans("Invalid login.");
                        return $output;
                    }

                    // Example of how to obtain an user:
                    $em = $this->getDoctrine()->getManager();
                    $user_repository = $em->getRepository('Proethos2ModelBundle:User');
                    $user = $user_repository->findOneBy(array('email' => $data['mail']));

                    if ( !$user ) { // create user
                        $em = $this->getDoctrine()->getManager();
                        $translator = $this->get('translator');
                        $locale = $request->getSession()->get('_locale');
                        $baseurl = $request->getScheme() . '://' . $request->getHttpHost() . $request->getBasePath();
                        $trans_repository = $em->getRepository('Gedmo\\Translatable\\Entity\\Translation');
                        $help_repository = $em->getRepository('Proethos2ModelBundle:Help');
                        // $role_repository = $em->getRepository('Proethos2ModelBundle:Role');

                        $slugify = new Slugify();
                        $givenName = explode(' ', $data['givenName']);
                        $surname = explode(' ', $data['surname']);
                        $username = $slugify->slugify(array_pop($surname).$givenName[0]);
                        $username = substr($username, 0, 8);
                        
                        $user = $user_repository->findOneBy(array('username' => $username));
                        if ( $user ) {
                            $username = $slugify->slugify($givenName[0].array_pop($surname));
                        }

                        $user = new User();
                        $user->setName($data['givenName'].' '.$data['surname']);
                        $user->setUsername($username);
                        $user->setEmail($data['mail']);
                        $user->setFirstAccess(false);
                        $user->setIsActive(false);

                        // adding user role
                        // $user->addProethos2Role($role_repository->findOneBy(array('slug' => 'investigator')));

                        $encoderFactory = $this->get('security.encoder_factory');
                        $encoder = $encoderFactory->getEncoder($user);
                        $salt = $user->getSalt(); // this should be different for every user
                        $password = $encoder->encodePassword(md5(date("YmdHis")), $salt);
                        $user->setPassword($password);

                        $user->cleanHashcode();

                        $help = $help_repository->find(208);
                        $translations = $trans_repository->findTranslations($help);
                        $text = $translations[$locale];
                        $body = $text['message'];
                        $body = str_replace("%home_url%", $baseurl, $body);
                        $body = str_replace("\r\n", "<br />", $body);
                        $body .= "<br /><br />";

                        // send email to the secretaries
                        $secretaries_emails = array();
                        foreach($user_repository->findAll() as $secretary) {
                            if(in_array('secretary', $secretary->getRolesSlug())) {
                                $secretaries_emails[] = $secretary->getEmail();
                            }
                        }

                        $message = \Swift_Message::newInstance()
                        ->setSubject("[proethos2] " . $translator->trans("New user on Proethos2 platform"))
                        ->setFrom($util->getConfiguration('committee.email'))
                        ->setTo($secretaries_emails)
                        ->setBody(
                            $body
                            ,   
                            'text/html'
                        );
                        $send = $this->get('mailer')->send($message);

                        $em->persist($user);
                        $em->flush();
                    }

                    //Handle getting or creating the user entity likely with a posted form
                    // The third parameter "main" can change according to the name of your firewall in security.yml
                    //$token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
                    $token = new UsernamePasswordToken($user, null, 'default', $user->getRoles());
                    $this->get('security.token_storage')->setToken($token);

                    // If the firewall name is not main, then the set value would be instead:
                    //$this->get('session')->set('_security_main', serialize($token));
                    $this->get('session')->set('_security_default', serialize($token));
                    
                    // Fire the login event manually
                    $event = new InteractiveLoginEvent($request, $token);
                    $this->get("event_dispatcher")->dispatch("security.interactive_login", $event);
                    
                    /*
                     * Now the user is authenticated !!!! 
                     * Do what you need to do now, like render a view, redirect to route etc.
                     */

                    return $this->redirectToRoute('default_security_target', array(), 301);
                } else {
                    $output['error'] = $translator->trans("Invalid state.");
                    return $output;
                }
            }
        }

        return $output;
    }

    /**
     * @Route("/login_check", name="login_check")
     */
    public function loginCheckAction()
    {
        // this controller will not be executed,
        // as the route is handled by the Security system
    }

    /**
     * @Route("/logout", name="logout_route")
     */
    public function logoutAction()
    {
        // this controller will not be executed,
        // as the route is handled by the Security system
    }

    /**
     * @Route("/logged", name="default_security_target")
     */
    public function loggedAction()
    {
        // if secretary, send to committee home
        $user = $this->get('security.token_storage')->getToken()->getUser();

        foreach(array('secretary', 'member-of-committee', 'member-ad-hoc') as $role) {
            if(in_array($role, $user->getRolesSlug())) {
                return $this->redirectToRoute('crud_committee_protocol_list', array(), 301);
            }
        }
        
        return $this->redirectToRoute('crud_investigator_protocol_list', array(), 301);
    }

    /**
     * @Route("/login/oauth2", name="login_oauth2")
     */
    public function loginAzureAction()
    {
        $auth_type = $this->container->getParameter('auth_type');

        if ( 'oauth2' == $auth_type ) {
            $router = $this->container->get('router');
            $redirect = $router->generate('login_route', array(), true);
            $azureClientId = ( $_ENV['AZURE_CLIENT_ID'] ) ? $_ENV['AZURE_CLIENT_ID'] : '';
            $azureClientSecret = ( $_ENV['AZURE_CLIENT_SECRET'] ) ? $_ENV['AZURE_CLIENT_SECRET'] : '';
            
            $provider = new Azure([
                'clientId'     => $azureClientId,
                'clientSecret' => $azureClientSecret,
                'redirectUri'  => $redirect
            ]);

            // Set to use v2 API, skip the line or set the value to Azure::ENDPOINT_VERSION_1_0 if willing to use v1 API
            $provider->defaultEndPointVersion = Azure::ENDPOINT_VERSION_2_0;

            $baseGraphUri = $provider->getRootMicrosoftGraphUri(null);
            $provider->scope = 'openid profile email offline_access ' . $baseGraphUri . '/User.Read';
            $authorizationUrl = $provider->getAuthorizationUrl(['scope' => $provider->scope]);
            $this->get('session')->set('OAuth2.state', $provider->getState());
            
            return $this->redirect($authorizationUrl, 301);
        } else {
            return $this->redirectToRoute('login_route', array(), 301);
        }
    }

    /**
     * @Route("/account/change_password", name="security_change_password")
     * @Template()
     */
    public function changePasswordAction()
    {
        $output = array();
        $request = $this->getRequest();
        $session = $request->getSession();
        $translator = $this->get('translator');
        $em = $this->getDoctrine()->getManager();

        $user = $this->get('security.token_storage')->getToken()->getUser();

        // checking if was a post request
        if($this->getRequest()->isMethod('POST')) {

            // getting post data
            $post_data = $request->request->all();
            
            // checking required fields
            foreach(array('change-password', 'password-confirm') as $field) {   
                if(!isset($post_data[$field]) or empty($post_data[$field])) {
                    $session->getFlashBag()->add('error', $translator->trans("Field '%field%' is required.", array("%field%" => $field)));
                    return $this->redirectToRoute('home', array(), 301);
                }
            }

            if($post_data['change-password'] != $post_data['password-confirm']) {
                $session->getFlashBag()->add('error', $translator->trans("Passwords don't match."));
                return $this->redirectToRoute('home', array(), 301);
            }

            $encoderFactory = $this->get('security.encoder_factory');
            $encoder = $encoderFactory->getEncoder($user);
            $salt = $user->getSalt(); // this should be different for every user
            $password = $encoder->encodePassword($post_data['change-password'], $salt);
            $user->setPassword($password);

            if($user->getFirstAccess()) {
                $user->setFirstAccess(false);
            }

            $em->persist($user);
            $em->flush();

            $session->getFlashBag()->add('success', $translator->trans("Password changed with success."));
            return $this->redirectToRoute('login_route', array(), 301);

        }

        return $output;
    }

    /**
     * @Route("/public/account/forgot-my-password", name="security_forgot_my_password")
     * @Template()
     */
    public function forgotMyPasswordAction()
    {
        $output = array();
        $request = $this->getRequest();
        $session = $request->getSession();
        $translator = $this->get('translator');
        $em = $this->getDoctrine()->getManager();
        $util = new Util($this->container, $this->getDoctrine());

        // getting post data
        $post_data = $request->request->all();

        $user_repository = $em->getRepository('Proethos2ModelBundle:User');

        $trans_repository = $em->getRepository('Gedmo\\Translatable\\Entity\\Translation');
        $help_repository = $em->getRepository('Proethos2ModelBundle:Help');
        // $help = $help_repository->findBy(array("id" => {id}, "type" => "mail"));
        // $translations = $trans_repository->findTranslations($help[0]);
        
        // checking if was a post request
        if($this->getRequest()->isMethod('POST')) {

            // getting post data
            $post_data = $request->request->all();
            
            // checking required fields
            foreach(array('email') as $field) {   
                if(!isset($post_data[$field]) or empty($post_data[$field])) {
                    $session->getFlashBag()->add('error', $translator->trans("Field '%field%' is required.", array("%field%" => $field)));
                    return $output;
                    // return $this->redirectToRoute('login', array(), 301);
                }
            }

            $user = $user_repository->findOneByEmail($post_data['email']);
            if(!$user) {
                $session->getFlashBag()->add('error', $translator->trans("Email not registered in platform."));
                return $output;
            }

            $baseurl = $request->getScheme() . '://' . $request->getHttpHost() . $request->getBasePath();

            $hashcode = $user->generateHashcode();
            $em->persist($user);
            $em->flush();

            // TODO need to get the relative path
            $url = $baseurl . "/public/account/reset_my_password?hashcode=" . $hashcode;

            $locale = $request->getSession()->get('_locale');
            $help = $help_repository->find(206);
            $translations = $trans_repository->findTranslations($help);
            $text = $translations[$locale];
            $body = $text['message'];
            $body = str_replace("%reset_password_url%", $url, $body);
            $body = str_replace("\r\n", "<br />", $body);
            $body .= "<br /><br />";

            $message = \Swift_Message::newInstance()
            ->setSubject("[proethos2] " . $translator->trans("Reset your password"))
            ->setFrom($util->getConfiguration('committee.email'))
            ->setTo($post_data['email'])
            ->setBody(
                $body
                ,   
                'text/html'
            );
            
            $send = $this->get('mailer')->send($message);

            $session->getFlashBag()->add('success', $translator->trans("Instructions have been sent to your email."));
        }

        return $output;
    }

    /**
     * @Route("/public/account/reset_my_password", name="security_reset_my_password")
     * @Template()
     */
    public function resetMyPasswordAction()
    {
        $output = array();
        $request = $this->getRequest();
        $session = $request->getSession();
        $translator = $this->get('translator');
        $em = $this->getDoctrine()->getManager();

        // getting post data
        $post_data = $request->request->all();

        $user_repository = $em->getRepository('Proethos2ModelBundle:User');

        $parameters = $request->query->all();
        
        if(!isset($parameters['hashcode'])) {
            throw $this->createNotFoundException($translator->trans('Invalid hashcode'));
        }

        $hashcode = $parameters['hashcode'];
        $user = $user_repository->findOneByHashcode($hashcode);

        if(!$user) {
            throw $this->createNotFoundException($translator->trans('No user found'));
        }

        // checking if was a post request
        if($this->getRequest()->isMethod('POST')) {

            // getting post data
            $post_data = $request->request->all();
            
            // checking required fields
            foreach(array('new-password', 'confirm-password') as $field) {   
                if(!isset($post_data[$field]) or empty($post_data[$field])) {
                    $session->getFlashBag()->add('error', $translator->trans("Field '%field%' is required.", array("%field%" => $field)));
                    return $this->redirectToRoute('home', array(), 301);
                }
            }

            if($post_data['new-password'] != $post_data['confirm-password']) {
                $session->getFlashBag()->add('error', $translator->trans("Passwords don't match."));
                return $this->redirectToRoute('home', array(), 301);
            }

            $encoderFactory = $this->get('security.encoder_factory');
            $encoder = $encoderFactory->getEncoder($user);
            $salt = $user->getSalt(); // this should be different for every user
            $password = $encoder->encodePassword($post_data['new-password'], $salt);
            $user->setPassword($password);

            $user->cleanHashcode();

            $em->persist($user);
            $em->flush();

            $session->getFlashBag()->add('success', $translator->trans("Password changed with success."));
            return $this->redirectToRoute('home', array(), 301);

        }

        return $output;
    }

    /**
     * @Route("/public/account/new", name="security_new_user")
     * @Template()
     */
    public function newUserAction()
    {
        $output = array();
        $request = $this->getRequest();
        $session = $request->getSession();
        $translator = $this->get('translator');
        $em = $this->getDoctrine()->getManager();
        $util = new Util($this->container, $this->getDoctrine());
        $locale = $request->getSession()->get('_locale');

        // getting post data
        $post_data = $request->request->all();

        $user_repository = $em->getRepository('Proethos2ModelBundle:User');
        $country_repository = $em->getRepository('Proethos2ModelBundle:Country');

        $countries = $country_repository->findBy(array(), array('name' => 'asc'));
        $output['countries'] = $countries;
        
        $output['content'] = array();

        $output['recaptcha_secret'] = $util->getConfiguration('recaptcha.secret');

        $trans_repository = $em->getRepository('Gedmo\\Translatable\\Entity\\Translation');
        $help_repository = $em->getRepository('Proethos2ModelBundle:Help');
        // $help = $help_repository->findBy(array("id" => {id}, "type" => "mail"));
        // $translations = $trans_repository->findTranslations($help[0]);
        
        // checking if was a post request
        if($this->getRequest()->isMethod('POST')) {

            // getting post data
            $post_data = $request->request->all();
            $output['content'] = $post_data;

            // checking required fields
            foreach(array('name', 'username', 'email', 'country', 'password', 'confirm-password') as $field) {   
                if(!isset($post_data[$field]) or empty($post_data[$field])) {
                    $session->getFlashBag()->add('error', $translator->trans("Field '%field%' is required.", array("%field%" => $field)));
                    return $output;
                }
            }

            // only check captcha if not in dev
            $secret = $output['recaptcha_secret'];
            if(!empty($secret) and strpos($_SERVER['HTTP_HOST'], 'localhost') < 0) {
                // RECAPTCHA

                // params to send to recapctha api
                $data = array(
                    "secret" => $secret,
                    "response" => $post_data['g-recaptcha-response'],
                    "remoteip" => $_SERVER['REMOTE_ADDR'],
                );

                // options from file_Get_contents
                $options = array(
                    'http' => array(
                        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                        'method'  => 'POST',
                        'content' => http_build_query($data)
                    )
                );
                
                // making the POST request to API
                $context  = stream_context_create($options);
                $response = file_get_contents("https://www.google.com/recaptcha/api/siteverify", false, $context);
                $response = json_decode($response);
                
                // if has problems, stop
                if(!$response->success) {
                    $session->getFlashBag()->add('error', $translator->trans("Have an error with captcha. Please try again."));
                    return $output;
                }
            }

            if($post_data['password'] != $post_data['confirm-password']) {
                $session->getFlashBag()->add('error', $translator->trans("Passwords don't match."));
                return $output;
            }

            $user = $user_repository->findOneByUsername($post_data['username']);
            if($user) {
                $session->getFlashBag()->add('error', $translator->trans("Username already registered in platform."));
                return $output;
            }

            $user = $user_repository->findOneByEmail($post_data['email']);
            if($user) {
                $session->getFlashBag()->add('error', $translator->trans("Email already registered in platform."));
                return $output;
            }

            $country = $country_repository->find($post_data['country']);

            $user = new User();
            $user->setCountry($country);
            $user->setName($post_data['name']);
            $user->setUsername($post_data['username']);
            $user->setEmail($post_data['email']);
            $user->setInstitution($post_data['institution']);
            $user->setFirstAccess(false);
            $user->setIsActive(false);

            $encoderFactory = $this->get('security.encoder_factory');
            $encoder = $encoderFactory->getEncoder($user);
            $salt = $user->getSalt(); // this should be different for every user
            $password = $encoder->encodePassword($post_data['password'], $salt);
            $user->setPassword($password);

            $user->cleanHashcode();

            $baseurl = $request->getScheme() . '://' . $request->getHttpHost() . $request->getBasePath();

            $help = $help_repository->find(207);
            $translations = $trans_repository->findTranslations($help);
            $text = $translations[$locale];
            $body = $text['message'];
            $body = str_replace("\r\n", "<br />", $body);
            $body .= "<br /><br />";

            // send email to the user
            $message = \Swift_Message::newInstance()
            ->setSubject("[proethos2] " . $translator->trans("Welcome to the Proethos2 platform!"))
            ->setFrom($util->getConfiguration('committee.email'))
            ->setTo($post_data['email'])
            ->setBody(
                $body
                ,   
                'text/html'
            );
            $send = $this->get('mailer')->send($message);

            $help = $help_repository->find(208);
            $translations = $trans_repository->findTranslations($help);
            $text = $translations[$locale];
            $body = $text['message'];
            $body = str_replace("%home_url%", $baseurl, $body);
            $body = str_replace("\r\n", "<br />", $body);
            $body .= "<br /><br />";

            // send email to the secretaries
            $secretaries_emails = array();
            foreach($user_repository->findAll() as $secretary) {
                if(in_array('secretary', $secretary->getRolesSlug())) {
                    $secretaries_emails[] = $secretary->getEmail();
                }
            }

            $message = \Swift_Message::newInstance()
            ->setSubject("[proethos2] " . $translator->trans("New user on Proethos2 platform"))
            ->setFrom($util->getConfiguration('committee.email'))
            ->setTo($secretaries_emails)
            ->setBody(
                $body
                ,   
                'text/html'
            );
            $send = $this->get('mailer')->send($message);

            $em->persist($user);
            $em->flush();

            $session->getFlashBag()->add('success', $translator->trans("User created with success. Wait for approval."));
            return $this->redirectToRoute('home', array(), 301);
        }

        return $output;
    }
}