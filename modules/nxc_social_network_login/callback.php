<?php
/**
 * @package nxcSocialNetworks
 * @author  Serhey Dolgushev <serhey.dolgushev@nxc.no>
 * @date    16 Sep 2012
 **/

$module = $Params['Module'];
$http   = eZHTTPTool::instance();
$ini    = eZINI::instance();

// Handling cancel button
if(
	$http->hasGetVariable( 'denied' )
	|| (
		$http->hasGetVariable( 'oauth_problem' )
		&& $http->getVariable( 'oauth_problem' ) == 'user_refused'
	) || (
		$http->hasGetVariable( 'error' )
		&& $http->getVariable( 'error' ) == 'access_denied'
	)
) {
	return $module->redirectTo( '/' );
}

// Get handler
try{
	$handler = nxcSocialNetworksLoginHandler::getInstanceByType( $Params['type'] );
} catch( Exception $e ) {
	eZDebug::writeError( $e->getMessage(), 'NXC Social Networks Login' );
	return $module->handleError( eZError::KERNEL_NOT_FOUND, 'kernel' );
}
// Get user`s remote ID
try{
	$remoteID = $handler->getUserRemoteID();
} catch( Exception $e ) {
	eZDebug::writeError( $e->getMessage(), 'NXC Social Networks Login' );
	return $module->handleError( eZError::KERNEL_NOT_FOUND, 'kernel' );
}
// Get user`s attributes
try{
	$attributes = $handler->getUserData();
} catch( Exception $e ) {
	eZDebug::writeError( $e->getMessage(), 'NXC Social Networks Login' );
	return $module->handleError( eZError::KERNEL_NOT_FOUND, 'kernel' );
}

// Trying to fetch current user from eZ Publish
$object = false;
$uniqueIdentifier = nxcSocialNetworksLoginHandler::getUniqueIdentifier();
if( $uniqueIdentifier == 'email' ) {
	$account = explode( '|', $attributes['user_account'] );
	if( isset( $account[1] ) ) {
		$user = eZUser::fetchByEmail( $account[1] );
		if( $user instanceof eZUser ) {
			$object = $user->attribute( 'contentobject' );
		}
	}
} else {
	$object = eZContentObject::fetchByRemoteID( $remoteID );
}

if( $object instanceof eZContentObject === false ) {
	// There is no eZ publish user, so we are creating one
	$userClassID = $ini->variable( 'UserSettings', 'UserClassID' );
	$userClass   = eZContentClass::fetch( $userClassID );
	if( $userClass instanceof eZContentClass === false ) {
		eZDebug::writeError( 'User calss does not exist', 'NXC Social Networks Login' );
		return $module->handleError( eZError::KERNEL_NOT_FOUND, 'kernel' );
	}

	$object = eZContentFunctions::createAndPublishObject(
		array(
			'parent_node_id'   => $ini->variable( 'UserSettings', 'DefaultUserPlacement' ),
			'class_identifier' => $userClass->attribute( 'identifier' ),
			'creator_id'       => $ini->variable( 'UserSettings', 'UserCreatorID' ),
			'section_id'       => $ini->variable( 'UserSettings', 'DefaultSectionID' ),
			'remote_id'        => $uniqueIdentifier == 'remote_id' ? $remoteID : null,
			'attributes'       => $attributes
		)
	);

	if( $object instanceof eZContentObject === false ) {
		eZDebug::writeError( 'User`s object isn`t created.', 'NXC Social Networks Login' );
		return $module->handleError( eZError::KERNEL_NOT_FOUND, 'kernel' );
	}
} else {
	// There is also eZ Publish user, so we are updating it, if it is needed
	$isUpdateNeeded = false;
	$dataMap        = $object->attribute( 'data_map' );
	// We are not updating user_account attribute
	unset( $attributes['user_account'] );

	foreach( $attributes as $identifier => $value ) {
		if( isset( $dataMap[ $identifier ] ) ) {
			$storedContent = $dataMap[ $identifier ]->toString();
			if( $identifier == 'image' ) {
				// We are comparing image sizes (stored in the eZ Publish vs social media avatar)
				$storedContent = explode( '|', $storedContent );
				$storedFile    = eZClusterFileHandler::instance( $storedContent[0] );
				if( $storedFile->metaData['size'] !== filesize( $attributes['image'] ) ) {
					$isUpdateNeeded = true;
					break;
				}
			} else {
				// We are comparing the content of rest attributes
				if( $storedContent != $value ) {
					$isUpdateNeeded = true;
					break;
				}
			}
		}
	}

	if( $isUpdateNeeded ) {
		// User should be logged in before update his profile
		$user = eZUser::fetch( $object->attribute( 'id' ) );
		if( $user instanceof eZUser ) {
			$user->loginCurrent();
		}

		eZContentFunctions::updateAndPublishObject(
			$object,
			array( 'attributes' => $attributes )
		);
	}
}

// Removing social media avatar (it was stored locally)
if( isset( $attributes['image'] ) ) {
	unlink( $attributes['image'] );
}

// Logging in into eZ Publish
if( $object instanceof eZContentObject ) {
	$user = eZUser::fetch( $object->attribute( 'id' ) );

	if( $user instanceof eZUser ) {
		$user->loginCurrent();
    
        $redirectionURI = false;
        if ( is_object( $user ) )
        {
            /*
             * Choose where to redirect the user to after successful login.
             * The checks are done in the following order:
             * 1. Per-user.
             * 2. Per-group.
             *    If the user object is published under several groups, main node is chosen
             *    (it its URI non-empty; otherwise first non-empty URI is chosen from the group list -- if any).
             *
             * See doc/features/3.8/advanced_redirection_after_user_login.txt for more information.
             */
    
            // First, let's determine which attributes we should search redirection URI in.
            $userUriAttrName  = '';
            $groupUriAttrName = '';
            if ( $ini->hasVariable( 'UserSettings', 'LoginRedirectionUriAttribute' ) )
            {
                $uriAttrNames = $ini->variable( 'UserSettings', 'LoginRedirectionUriAttribute' );
                if ( is_array( $uriAttrNames ) )
                {
                    if ( isset( $uriAttrNames['user'] ) )
                        $userUriAttrName = $uriAttrNames['user'];
    
                    if ( isset( $uriAttrNames['group'] ) )
                        $groupUriAttrName = $uriAttrNames['group'];
                }
            }
    
            $userObject = $user->attribute( 'contentobject' );
    
            // 1. Check if redirection URI is specified for the user
            $userUriSpecified = false;
            if ( $userUriAttrName )
            {
                $userDataMap = $userObject->attribute( 'data_map' );
                if ( !isset( $userDataMap[$userUriAttrName] ) )
                {
                    eZDebug::writeWarning( "Cannot find redirection URI: there is no attribute '$userUriAttrName' in object '" .
                                           $userObject->attribute( 'name' ) .
                                           "' of class '" .
                                           $userObject->attribute( 'class_name' ) . "'." );
                }
                elseif ( ( $uriAttribute = $userDataMap[$userUriAttrName] ) &&
                         ( $uri = $uriAttribute->attribute( 'content' ) ) )
                {
                    $redirectionURI = $uri;
                    $userUriSpecified = true;
                }
            }
    
            // 2.Check if redirection URI is specified for at least one of the user's groups (preferring main parent group).
            if ( !$userUriSpecified && $groupUriAttrName && $user->hasAttribute( 'groups' ) )
            {
                $groups = $user->attribute( 'groups' );
    
                if ( isset( $groups ) && is_array( $groups ) )
                {
                    $chosenGroupURI = '';
                    foreach ( $groups as $groupID )
                    {
                        $group = eZContentObject::fetch( $groupID );
                        $groupDataMap = $group->attribute( 'data_map' );
                        $isMainParent = ( $group->attribute( 'main_node_id' ) == $userObject->attribute( 'main_parent_node_id' ) );
    
                        if ( !isset( $groupDataMap[$groupUriAttrName] ) )
                        {
                            eZDebug::writeWarning( "Cannot find redirection URI: there is no attribute '$groupUriAttrName' in object '" .
                                                   $group->attribute( 'name' ) .
                                                   "' of class '" .
                                                   $group->attribute( 'class_name' ) . "'." );
                            continue;
                        }
                        $uri = $groupDataMap[$groupUriAttrName]->attribute( 'content' );
                        if ( $uri )
                        {
                            if ( $isMainParent )
                            {
                                $chosenGroupURI = $uri;
                                break;
                            }
                            elseif ( !$chosenGroupURI )
                                $chosenGroupURI = $uri;
                        }
                    }
    
                    if ( $chosenGroupURI ) // if we've chose an URI from one of the user's groups.
                        $redirectionURI = $chosenGroupURI;
                }
            }
        }
        
        if ($redirectionURI)
        {
            $redirectURI = $redirectionURI;
        }
        elseif( $http->hasGetVariable( 'login_redirect_url' ) ) {
			$redirectURI = $http->getVariable( 'login_redirect_url' );
		} elseif(
			$handler instanceof nxcSocialNetworksLoginHandlerFacebook === false
			&& $http->hasGetVariable( 'state' )
		) {
			$redirectURI = base64_decode( $http->getVariable( 'state' ) );
		} elseif( $http->hasSessionVariable( 'LastAccessesURI' ) && $http->sessionVariable( 'LastAccessesURI' ) !== '' ) {
			$redirectURI = $http->sessionVariable( 'LastAccessesURI' );
		} elseif( ( $refferer = eZSys::serverVariable( 'HTTP_REFERER', true ) ) !== null ) {
			$redirectURI = $refferer;
		} else {
			$redirectURI = $ini->variable( 'SiteSettings', 'DefaultPage' );
		}

		if( strpos( $redirectURI, 'user/login' ) !== false ) {
			$redirectURI = $ini->variable( 'SiteSettings', 'DefaultPage' );
		}
        
        ezpEvent::getInstance()->notify('user/login');
        
		return $module->redirectTo( $redirectURI );
	}
}
?>
