<?php

class ConfirmAccountsPage extends SpecialPage {
	protected $queueType = -1;
	protected $acrID = 0;
	protected $file = '';

	protected $showHeld = false;
	protected $showRejects = false;
	protected $showStale = false;

	/** @var UserAccountRequest */
	protected $accountReq;
	protected $reqUsername;
	protected $reqType;
	protected $reqBio;
	/** @var array */
	protected $reqAreas;

	protected $submitType;
	protected $reason;

	function __construct() {
		parent::__construct( 'ConfirmAccounts', 'confirmaccount' );
	}

	public function doesWrites() {
		return true;
	}

	function execute( $par ) {
		global $wgAccountRequestTypes;

		$reqUser = $this->getUser();
		$request = $this->getRequest();

		if ( !$reqUser->isAllowed( 'confirmaccount' ) ) {
			throw new PermissionsError( 'confirmaccount' );
		} elseif ( !$reqUser->getID() ) {
			throw new PermissionsError( 'user' );
		}

		$this->setHeaders();

		# Use the special page param to act as a super type.
		# Convert this to its integer form.
		$this->queueType = -1;
		foreach ( $wgAccountRequestTypes as $i => $params ) {
			if ( $params[0] === $par ) {
				$this->queueType = $i;
				break;
			}
		}
		# User account request ID
		$this->acrID = $request->getIntOrNull( 'acrid' );
		# Attachment file name to view
		$this->file = $request->getVal( 'file' );

		# Held requests hidden by default
		$this->showHeld = $request->getBool( 'wpShowHeld' );
		# Show stale requests
		$this->showStale = $request->getBool( 'wpShowStale' );
		# For viewing rejected requests (stale requests count as rejected)
		$this->showRejects = $request->getBool( 'wpShowRejects' );

		// Showing a file
		if ( $this->file ) {
			$this->showFile( $this->file );
			return; // nothing else to do
		// Showing or confirming an account request
		} elseif ( $this->acrID ) {
			# Load areas user plans to be active in...
			$this->reqAreas = [];
			foreach ( ConfirmAccount::getUserAreaConfig() as $name => $conf ) {
				$formName = "wpArea-" . htmlspecialchars( str_replace( ' ', '_', $name ) );
				$this->reqAreas[$name] = $request->getInt( $formName, -1 );
			}
			# Load in the UserAccountRequest obj
			$this->loadAccountRequest( $this->acrID, $request->wasPosted() );
			if ( $request->wasPosted() ) {
				# For renaming to alot for collisions with other local requests
				# that were accepted and added to some global auth system first
				$this->reqUsername = trim( $request->getText( 'wpNewName' ) );
				# For changing the position recieved by requester
				$this->reqType = $request->getIntOrNull( 'wpType' );
				if ( !isset( $wgAccountRequestTypes[$this->reqType] ) ) {
					$this->reqType = null;
				}
				# For removing private info or such from bios
				$this->reqBio = $request->getText( 'wpNewBio' );
				# Action the admin is taking and why
				$this->submitType = $request->getVal( 'wpSubmitType' );
				$this->reason = $request->getText( 'wpReason' );
				# Check if this is a valid submission...
				$token = $request->getVal( 'wpEditToken' );
				if ( $reqUser->matchEditToken( $token, $this->acrID ) ) {
					$this->doAccountConfirmSubmit();
				} else {
					$this->showAccountConfirmForm( $this->msg( 'sessionfailure' )->escaped() );
				}
			} else {
				$this->showAccountConfirmForm();
			}
		// Showing all account requests in a queue
		} elseif ( $this->queueType != -1 ) {
			$this->showList();
		// Showing all account request queues
		} else {
			$this->showQueues();
		}

		// Show what queue we are in and links to the others
		$this->addQueueSubtitleLinks();

		$this->getOutput()->addModules( 'ext.confirmAccount' ); // CSS
	}

	protected function addQueueSubtitleLinks() {
		$titleObj = $this->getFullTitle();

		$linkRenderer = $this->getLinkRenderer();

		# Show other sub-queue links. Grey out the current one.
		# When viewing a request, show them all.
		if ( $this->acrID || $this->showStale || $this->showRejects || $this->showHeld ) {
			$listLink = $linkRenderer->makeKnownLink(
				$titleObj,
				$this->msg( 'confirmaccount-showopen' )->text()
			);
		} else {
			$listLink = $this->msg( 'confirmaccount-showopen' )->escaped();
		}
		if ( $this->acrID || !$this->showHeld ) {
			$listLink = $this->getLanguage()->pipeList( [
				$listLink,
				$linkRenderer->makeKnownLink(
					$titleObj,
					$this->msg( 'confirmaccount-showheld' )->text(),
					[],
					[ 'wpShowHeld' => 1 ]
				)
			] );
		} else {
			$listLink = $this->getLanguage()->pipeList( [
				$listLink,
				$this->msg( 'confirmaccount-showheld' )->escaped()
			] );
		}
		if ( $this->acrID || !$this->showRejects ) {
			$listLink = $this->getLanguage()->pipeList( [
				$listLink,
				$linkRenderer->makeKnownLink(
					$titleObj,
					$this->msg( 'confirmaccount-showrej' )->text(),
					[],
					[ 'wpShowRejects' => 1 ]
				)
			] );
		} else {
			$listLink = $this->getLanguage()->pipeList( [
				$listLink,
				$this->msg( 'confirmaccount-showrej' )->escaped()
			] );
		}
		if ( $this->acrID || !$this->showStale ) {
			$listLink = $this->getLanguage()->pipeList( [
				$listLink,
				$linkRenderer->makeKnownLink(
					$titleObj,
					$this->msg( 'confirmaccount-showexp' )->text(),
					[],
					[ 'wpShowStale' => 1 ]
				)
			] );
		} else {
			$listLink = $this->getLanguage()->pipeList( [
				$listLink,
				$this->msg( 'confirmaccount-showexp' )->escaped()
			] );
		}

		# Say what queue we are in...
		if ( $this->queueType != -1 ) {
			$viewall = $linkRenderer->makeKnownLink(
				$this->getPageTitle(),
				$this->msg( 'confirmaccount-all' )->text()
			);

			// Give grep a chance to find the usages: confirmaccount-type-0, confirmaccount-type-1
			$this->getOutput()->setSubtitle(
				"<strong>" . $this->msg( 'confirmaccount-type' )->escaped() . " <i>" .
				$this->msg( "confirmaccount-type-{$this->queueType}" )->escaped() .
				"</i></strong> [{$listLink}] <strong>{$viewall}</strong>" );
		}
	}

	protected function showQueues() {
		global $wgAccountRequestTypes;

		$out = $this->getOutput();

		$out->addWikiMsg( 'confirmaccount-maintext' );
		$out->wrapWikiMsg( "<p><strong>$1</strong></p>", 'confirmaccount-types' );

		# List each queue and some information about it...
		$out->addHTML( '<ul>' );
		foreach ( $wgAccountRequestTypes as $i => $params ) {
			$titleObj = SpecialPage::getTitleFor( 'ConfirmAccounts', $params[0] );
			$counts = ConfirmAccount::getOpenRequestCount( $i );

			$linkRenderer = $this->getLinkRenderer();

			$open = '<b>';
			$open .= $linkRenderer->makeKnownLink(
				$titleObj,
				$this->msg( 'confirmaccount-q-open' )->text(),
				[],
				[ 'wpShowHeld' => 0 ]
			);
			$open .= '</b> [' . $counts['open'] . ']';

			$held = $linkRenderer->makeKnownLink(
				$titleObj,
				$this->msg( 'confirmaccount-q-held' )->text(),
				[],
				[ 'wpShowHeld' => 1 ]
			);
			$held .= ' [' . $counts['held'] . ']';

			$rejects = $linkRenderer->makeKnownLink(
				$titleObj,
				$this->msg( 'confirmaccount-q-rej' )->text(),
				[],
				[ 'wpShowRejects' => 1 ]
			);
			$rejects .= ' [' . $counts['rejected'] . ']';

			$stale = '<i>';
			$stale .= $linkRenderer->makeKnownLink(
				$titleObj,
				$this->msg( 'confirmaccount-q-stale' )->text(),
				[],
				[ 'wpShowStale' => 1 ]
			);
			$stale .= '</i>';

			// Give grep a chance to find the usages: confirmaccount-type-0, confirmaccount-type-1
			$out->addHTML( "<li><i>" . $this->msg( "confirmaccount-type-$i" )->escaped() . "</i>" );
			$out->addHTML( $this->msg( 'word-separator' )->plain() );
			$params = $this->getLanguage()->pipeList( [ $open, $held, $rejects, $stale ] );
			$out->addHTML( $this->msg( 'parentheses' )->rawParams( $params )->escaped() );
			$out->addHTML( '</li>' );
		}

		$out->addHTML( '</ul>' );
	}

	/**
	 * @param $msg string
	 */
	protected function showAccountConfirmForm( $msg = '' ) {
		global $wgAccountRequestTypes;

		$out = $this->getOutput();
		$reqUser = $this->getUser();
		$titleObj = $this->getFullTitle();

		$accountReq = $this->accountReq; // convenience
		if ( !$accountReq || $accountReq->isDeleted() && !$this->showRejects ) {
			$out->addHTML( $this->msg( 'confirmaccount-badid' )->escaped() );
			$out->returnToMain( true, $titleObj );
			return;
		}

		# Output any failure message
		if ( $msg != '' ) {
			$out->addHTML( '<div class="errorbox">' . $msg . '</div><div class="visualClear"></div>' );
		}

		$out->addWikiMsg( 'confirmaccount-text' );

		$rejectTimestamp = $accountReq->getRejectTimestamp();
		$heldTimestamp = $accountReq->getHeldTimestamp();
		$reason = strlen( $accountReq->getHandlingComment() )
			? htmlspecialchars( $accountReq->getHandlingComment() )
			: $this->msg( 'confirmaccount-noreason' )->escaped();
		$adminId = $accountReq->getHandlingUser();

		if ( $rejectTimestamp ) {
			$datim = $this->getLanguage()->timeanddate( $rejectTimestamp, true );
			$date = $this->getLanguage()->date( $rejectTimestamp, true );
			$time = $this->getLanguage()->time( $rejectTimestamp, true );
			# Auto-rejected requests have a user ID of zero
			if ( $adminId ) {
				$out->addHTML( '<p><b>' . $this->msg( 'confirmaccount-reject',
					User::whoIs( $adminId ), $datim, $date, $time )->parse() . '</b></p>' );
				$out->addHTML(
					'<p><strong>' . $this->msg( 'confirmaccount-rational' )->escaped() . '</strong><i> ' .
					$reason . '</i></p>'
				);
			} else {
				$out->addHTML( "<p><i> $reason </i></p>" );
			}
		} elseif ( $heldTimestamp ) {
			$datim = $this->getLanguage()->timeanddate( $heldTimestamp, true );
			$date = $this->getLanguage()->date( $heldTimestamp, true );
			$time = $this->getLanguage()->time( $heldTimestamp, true );

			$out->addHTML( '<p><b>' . $this->msg( 'confirmaccount-held',
				User::whoIs( $adminId ), $datim, $date, $time )->parse() . '</b></p>' );
			$out->addHTML(
				'<p><strong>' . $this->msg( 'confirmaccount-rational' )->escaped() . '</strong><i> ' .
				$reason . '</i></p>'
			);
		}

		$form = Xml::openElement( 'form', [ 'method' => 'post', 'name' => 'accountconfirm',
			'action' => $titleObj->getLocalUrl() ] );

		$form .= "<fieldset>";
		$form .= '<legend>' . $this->msg( 'confirmaccount-leg-user' )->escaped() . '</legend>';
		$form .= '<table style="padding:4px;">';
		$form .= "<tr><td>" . Xml::label( $this->msg( 'username' )->text(), 'wpNewName' ) . "</td>";
		//show the user's Scratch username and a link to their Scratch profile
		$form .= "<td>" . '<a href="https://scratch.mit.edu/users/' . htmlspecialchars(str_replace(' ', '_', $this->reqUsername)) . '">' . htmlspecialchars(str_replace(' ', '_', $this->reqUsername)) . '</a>';
		$form .= Xml::input( 'wpNewName', 30, $this->reqUsername, [
			'id' => 'wpNewName',
			'type' => 'hidden'
		] ) . "</td></tr>\n";
		
		//get the user's Scratcher status and join date from their profile page
		$profile_html = file_get_contents('https://scratch.mit.edu/users/' . rawurlencode(str_replace(' ', '_', $this->reqUsername)));
		preg_match('%<span class="group">\s+([A-Za-z ]+)\s+</span>\s+Joined <span title="\d{4}-\d{2}-\d{2}">(.*?)</span> ago%', $profile_html, $profile_html_matches);
		if (sizeof($profile_html_matches) == 3) {
			//show scratcher status
			$form .= '<tr><td>' . $this->msg('confirmaccount-scratcherstatus') . '</td><td';
			if ($profile_html_matches[1] == 'New Scratcher') { //show a warning if the user is a New Scratcher
				$form .= ' style="color:#F00; font-weight:bold"';
			}
			$form .= '>' . $profile_html_matches[1] . '</td></tr>';
			
			//show join date
			$form .= '<tr><td>' . $this->msg('confirmaccount-joined') . '</td><td';
			//(\d+ years|(?:\d{2}|[2-9]) months)
			if (!preg_match('%^(\d+[^a-z0-9]+years?|(?:\d{2}|[2-9])[^a-z0-9]+months)%', $profile_html_matches[2])) { //show a warning if the user joined less than two months ago
				$form .= ' style="color:#F00; font-weight:bold"';
			}
			$form .= '>' . $profile_html_matches[2] . '</td></tr>';
		}
		unset($profile_html);
		unset($profile_html_matches);
		
		$econf = '';
		if ( $accountReq->getEmailAuthTimestamp() ) {
			$econf = ' <strong>' . $this->msg( 'confirmaccount-econf' )->escaped() . '</strong>';
		}
		$form .= "<tr><td>" . $this->msg( 'confirmaccount-email' )->escaped() . "</td>";
		$form .= "<td>" . htmlspecialchars( $accountReq->getEmail() ) . $econf . "</td></tr>\n";
		if ( count( $wgAccountRequestTypes ) > 1 ) {
			$options = [];
			$form .= "<tr><td><strong>" . $this->msg(
				'confirmaccount-reqtype'
			)->escaped() . "</strong></td><td>";
			foreach ( $wgAccountRequestTypes as $i => $params ) {
				// Give grep a chance to find the usages: confirmaccount-pos-0, confirmaccount-pos-1
				$options[] = Xml::option( $this->msg(
					"confirmaccount-pos-$i"
				)->text(), $i, ( $i == $this->reqType ) );
			}
			$form .= Xml::openElement( 'select', [ 'name' => "wpType" ] );
			$form .= implode( "\n", $options );
			$form .= Xml::closeElement( 'select' ) . "\n";
			$form .= "</td></tr>\n";
		}
		$form .= '</table></fieldset>';

		$userAreas = ConfirmAccount::getUserAreaConfig();
		$linkRenderer = $this->getLinkRenderer();
		if ( $this->hasItem( 'AreasOfInterest' ) && count( $userAreas ) > 0 ) {
			$form .= '<fieldset>';
			$form .= '<legend>' . $this->msg( 'confirmaccount-leg-areas' )->escaped() . '</legend>';

			$form .= "<div style='height:150px; overflow:scroll; background-color:#f9f9f9;'>";
			$form .= "<table style='border-spacing: 5px; padding: 0; background-color: #f9f9f9;'>
			<tr style='vertical-align:top;'>";
			$count = 0;
			foreach ( $userAreas as $name => $conf ) {
				$count++;
				if ( $count > 5 ) {
					$form .= "</tr><tr valign='top'>";
					$count = 1;
				}
				$formName = "wpArea-" . htmlspecialchars( str_replace( ' ', '_', $name ) );
				if ( $conf['project'] != '' ) {
					$pg = $linkRenderer->makeKnownLink(
						Title::newFromText( $conf['project'] ),
						$this->msg( 'requestaccount-info' )->text()
					);
				} else {
					$pg = '';
				}
				$form .= "<td>" .
					Xml::checkLabel( $name, $formName, $formName, $this->reqAreas[$name] > 0 ) .
					" {$pg}</td>\n";
			}
			$form .= "</tr></table></div>";
			$form .= '</fieldset>';
		}

		if ( $this->hasItem( 'Biography' ) || $this->hasItem( 'RealName' ) ) {
			$form .= '<fieldset>';
			$form .= '<legend>' . $this->msg( 'confirmaccount-leg-person' )->escaped() . '</legend>';
			if ( $this->hasItem( 'RealName' ) ) {
				$form .= '<table style="padding:4px;">';
				$form .= "<tr><td>" . $this->msg( 'confirmaccount-real' )->escaped() . "</td>";
				$form .= "<td>" . htmlspecialchars( $accountReq->getRealName() ) . "</td></tr>\n";
				$form .= '</table>';
			}
			if ( $this->hasItem( 'Biography' ) ) {
				$form .= "<p>" . $this->msg( 'confirmaccount-bio' )->escaped() . "\n";
				$form .= "<textarea tabindex='1' name='wpNewBio' id='wpNewBio' rows='12'
					cols='80' style='width: 100%; background-color: #f9f9f9;'>" .
					htmlspecialchars( $this->reqBio ) .
					"</textarea></p>\n";
			}
			$form .= '</fieldset>';
		}

		if ( $this->hasItem( 'CV' ) || $this->hasItem( 'Notes' ) || $this->hasItem( 'Links' ) ) {
			$form .= '<fieldset>';
			$form .= '<legend>' . $this->msg( 'confirmaccount-leg-other' )->escaped() . '</legend>';
			if ( $this->hasItem( 'CV' ) ) {
				$form .= '<p>' . $this->msg( 'confirmaccount-attach' )->escaped() . ' ';
				if ( $accountReq->getFileName() !== null ) {
					$form .= $linkRenderer->makeKnownLink(
						$titleObj,
						$accountReq->getFileName(),
						[],
						[ 'file' => $accountReq->getFileStorageKey() ]
					);
				} else {
					$form .= $this->msg( 'confirmaccount-none-p' )->escaped();
				}
			}
			if ( $this->hasItem( 'Notes' ) ) {
				$form .= "</p><p>" . $this->msg( 'confirmaccount-notes' )->escaped() . "\n";
				$form .= "<textarea tabindex='1' readonly='readonly' name='wpNotes'
					id='wpNotes' rows='3' cols='80' style='width: 100%'>" .
					htmlspecialchars( $accountReq->getNotes() ) .
					"</textarea></p>\n";
			}
			if ( $this->hasItem( 'Links' ) ) {
				$form .= "<p>" . $this->msg( 'confirmaccount-urls' )->escaped() . "</p>\n";
				$form .= self::parseLinks( $accountReq->getUrls() );
			}
			$form .= '</fieldset>';
		}

		if ( $reqUser->isAllowed( 'requestips' ) ) {
			$blokip = SpecialPage::getTitleFor( 'Block' );
			$link = $linkRenderer->makeKnownLink(
				$blokip,
				$this->msg( 'confirmaccount-blockip' ),
				[],
				[ 'ip' => $accountReq->getIP(), '&wpCreateAccount' => 1 ]
			);
			$form .= '<fieldset>';
			$form .= '<legend>' . $this->msg( 'confirmaccount-leg-ip' )->escaped() . '</legend>';
			$wordSeparator = $this->msg( 'word-separator' )->plain();
			$form .= "<p>";
			// @todo FIXME: Bad i18n. Should probably be something like
			// "confirmaccount-ip $1 ($2)" to get rid of this mess.
			$form .= $this->msg( 'confirmaccount-ip' )->escaped();
			$form .= $wordSeparator;
			$form .= htmlspecialchars( $accountReq->getIP() );
			$form .= $wordSeparator;
			$form .= $this->msg( 'parentheses' )->rawParams( $link )->escaped();
			$form .= "</p>\n";
			if ( $accountReq->getXFF() ) {
				$form .= "<p>" . $this->msg( 'confirmaccount-xff' )->escaped() .
				$wordSeparator . htmlspecialchars( $accountReq->getXFF() ) . "</p>\n";
			}
			if ( $accountReq->getAgent() ) {
				$form .= "<p>" . $this->msg( 'confirmaccount-agent' )->escaped() .
				$wordSeparator . htmlspecialchars( $accountReq->getAgent() ) . "</p>\n";
			}
			$form .= '</fieldset>';
		}
		
		
		/*
		Row format for user credentials:
		stdClass Object
(
    [acd_id] => 649
    [acd_user_id] => 1339
    [acd_real_name] => 
    [acd_email] => 725820@810540.com
    [acd_email_authenticated] => 
    [acd_bio] => 
    [acd_notes] => I would like to improve any pages I can, specifically the "Chatroom" article at https://wiki.scratch.mit.edu/wiki/Chatroom by adding a tutorial for a safe chat. This would really act as a flashlight for Scratchers in the dark about how chatrooms work.
I have actually looked at wikis such as Wikipedia for some time, thinking how they could be improved.
Of course, all my edits/articles will follow the Scratch Wiki Guidelines and the Scratch Community Guidelines.:B:bbda8287:8efffdbfedaf2f0e18bd66d6c7e58e45
    [acd_urls] => 
    [acd_ip] => 210.6.32.67
    [acd_xff] => 
    [acd_agent] => Mozilla/5.0 (Windows NT 6.3; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36
    [acd_filename] => 
    [acd_storage_key] => scratchwiki:acctrequest:ip:210.6.32.67
    [acd_areas] => 
    [acd_registration] => 20170125065719
    [acd_accepted] => 20170125232005
    [acd_user] => 443
    [acd_comment] => {{Welcome}}
)

		*/
		//search for possible alt accounts
		$ip = $accountReq->getIP();
		$alts = array();
				
		//get any previously requested accounts from this IP
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			array('account_credentials', 'user'),
			array('user_name'),
			array(
				'acd_ip' => $ip
			),
			__METHOD__,
			array(),
			array('user' =>  array('LEFT JOIN', 'user_id=acd_user_id'))
		);
		
		foreach ($res as $row) {
			$alts[] = $row->user_name;
		}
		
		//get any edits that may have been made from this IP
		if (ExtensionRegistry::getInstance()->isLoaded( 'CheckUser' )) { //ONLY do this if CheckUser is installed, as this depends on the CheckUser infrastructure
			$res = $dbr->select(
				array('cu_changes', 'user'),
				array('DISTINCT user_name'),
				array(
					'cuc_ip_hex' => IP::toHex($ip)
				),
				__METHOD__,
				array(),
				array('user' =>  array('LEFT JOIN', 'user_id=cuc_user'))
			);
			
			foreach ($res as $row) {
				if ($row->user_name != '') {
					$alts[] = $row->user_name;
				}
			}
		}
		
		$alts = array_unique($alts); //remove any duplicate entries
		
		if (!empty($alts)) {
			$form .= '<fieldset>';
			$form .= '<legend style="color:#F00; font-weight:bold">' . $this->msg('confirmaccount-warning') . '</legend>';
			$form .= '<strong>' . $this->msg('confirmaccount-altwarning') . '</strong>';
			$form .= '<ul><li>' . implode('</li><li>', $alts) . '</li></ul>';
			$form .= '</fieldset>';
		}


		$form .= '<fieldset>';
		$form .= '<legend>' . $this->msg( 'confirmaccount-legend' )->escaped() . '</legend>';
		$form .= "<strong>" . $this->msg( 'confirmaccount-confirm' )->parse() . "</strong>\n";
		$form .= "<table style='padding:5px;'><tr>";
		$form .= "<td>" . Xml::radio( 'wpSubmitType', 'accept', $this->submitType == 'accept',
			[
				'id' => 'submitCreate',
				'onclick' => 'document.getElementById("wpComment").style.display="block"'
			]
		);
		$form .= ' ' . Xml::label(
			$this->msg( 'confirmaccount-create' )->text(), 'submitCreate'
		) . "</td>\n";
		$form .= "<td>" . Xml::radio( 'wpSubmitType', 'reject', $this->submitType == 'reject',
			[
				'id' => 'submitDeny', 'onclick' => 'document.getElementById("wpComment").style.display="block"'
			]
		);
		$form .= ' ' . Xml::label(
			$this->msg( 'confirmaccount-deny' )->text(), 'submitDeny'
		) . "</td>\n";
		$form .= "<td>" . Xml::radio( 'wpSubmitType', 'hold', $this->submitType == 'hold',
			[
				'id' => 'submitHold', 'onclick' => 'document.getElementById("wpComment").style.display="block"'
			]
		);
		$form .= ' ' . Xml::label(
			$this->msg( 'confirmaccount-hold' )->text(), 'submitHold'
		) . "</td>\n";
		$form .= "<td>" . Xml::radio( 'wpSubmitType', 'spam', $this->submitType == 'spam',
			[
				'id' => 'submitSpam', 'onclick' => 'document.getElementById("wpComment").style.display="none"'
			]
		);
		$form .= ' ' . Xml::label(
			$this->msg( 'confirmaccount-spam' )->text(), 'submitSpam'
		) . "</td>\n";
		$form .= "</tr></table>";
		$form .= "<div id='wpComment'><p>" . $this->msg( 'confirmaccount-reason' )->escaped() . "</p>\n";
		$form .= "<p>
		<textarea name='wpReason' id='wpReason' rows='3' cols='80' style='width:80%; display=block;'>" .
			htmlspecialchars( $this->reason ) . "</textarea></p></div>\n";
		$form .= "<p>" . Xml::submitButton( $this->msg( 'confirmaccount-submit' )->text() ) . "</p>\n";
		$form .= '</fieldset>';

		$form .= Html::Hidden( 'title', $titleObj->getPrefixedDBKey() ) . "\n";
		$form .= Html::Hidden( 'action', 'reject' );
		$form .= Html::Hidden( 'acrid', $accountReq->getId() );
		$form .= Html::Hidden( 'wpShowRejects', $this->showRejects );
		$form .= Html::Hidden( 'wpEditToken', $reqUser->getEditToken( $accountReq->getId() ) ) . "\n";
		$form .= Xml::closeElement( 'form' );

		$out->addHTML( $form );

		global $wgMemc;
		# Set a key to who is looking at this request.
		# Have it expire in 10 minutes...
		$key = wfMemcKey( 'acctrequest', 'view', $accountReq->getId() );
		$wgMemc->set( $key, $reqUser->getID(), 60 * 10 );
	}

	protected function hasItem( $name ) {
		global $wgConfirmAccountRequestFormItems;

		return $wgConfirmAccountRequestFormItems[$name]['enabled'];
	}

	/**
	 * Show a private file requested by the visitor.
	 * @param $key string
	 */
	protected function showFile( $key ) {
		global $wgConfirmAccountFSRepos;

		$out = $this->getOutput();
		$request = $this->getRequest();

		$out->disable();

		# We mustn't allow the output to be Squid cached, otherwise
		# if an admin previews a private image, and it's cached, then
		# a user without appropriate permissions can toddle off and
		# nab the image, and Squid will serve it
		$request->response()->header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', 0 ) . ' GMT' );
		$request->response()->header( 'Cache-Control: no-cache, no-store, max-age=0, must-revalidate' );
		$request->response()->header( 'Pragma: no-cache' );

		$repo = new FSRepo( $wgConfirmAccountFSRepos['accountreqs'] );
		$path = $repo->getZonePath( 'public' ) . '/' .
			UserAccountRequest::relPathFromKey( $key );

		$repo->streamFile( $path );
	}

	protected function doAccountConfirmSubmit() {
		if ( !$this->accountReq ) {
			$this->showAccountConfirmForm( $this->msg( 'confirmaccount-badid' )->escaped() );
			return;
		}

		# Build submission object...
		$areaSet = []; // make a simple list of interests
		foreach ( $this->reqAreas as $area => $val ) {
			if ( $val > 0 ) {
				$areaSet[] = $area;
			}
		}
		$submission = new AccountConfirmSubmission(
			$this->getUser(),
			$this->accountReq,
			[
				'userName' => $this->reqUsername,
				'bio'      => $this->reqBio,
				'type'     => $this->reqType,
				'areas'    => $areaSet,
				'action'   => $this->submitType,
				'reason'   => $this->reason
			]
		);

		# Actually submit!
		list( $status, $msg, $url ) = $submission->submit( $this->getContext() );

		# Check for error messages
		if ( $status !== true ) {
			$this->showAccountConfirmForm( $msg );
			return;
		}

		# Done!
		if ( $url ) {
			$this->getOutput()->redirect( $url );
		} else {
			$this->showSuccess( $this->submitType, $this->reqUsername, (array)$msg );
		}
	}

	/**
	 * Get requested account request row and load some fields
	 * @param $id int
	 * @param $wasPosted bool
	 * @return void
	 */
	protected function loadAccountRequest( $id, $wasPosted ) {
		$from = $wasPosted ? 'dbmaster' : 'dbslave';
		$this->accountReq = UserAccountRequest::newFromId( $id, $from );
		# Check if parameters are to be overridden
		if ( $this->accountReq ) {
			$this->reqUsername = ( $this->reqUsername != '' )
				? $this->reqUsername // overriden by admin
				: $this->accountReq->getName();
			$this->reqBio = ( $this->reqBio != '' )
				? $this->reqBio // overriden by admin
				: $this->accountReq->getBio();
			$this->reqType = !is_null( $this->reqType )
				? $this->reqType // overriden by admin
				: $this->accountReq->getType();

			$origAreas = $this->accountReq->getAreas();
			foreach ( $this->reqAreas as $area => $within ) {
				# If admin didn't set any of these checks, go back to how the user set them.
				# On GET requests, the admin probably didn't set anything.
				if ( $within == -1 ) {
					if ( in_array( $area, $origAreas ) ) {
						$this->reqAreas[$area] = 1;
					} else {
						$this->reqAreas[$area] = 0;
					}
				}
			}
		}
	}

	/**
	 * Extract a list of all recognized HTTP links in the text.
	 * @param string $text
	 * @return string $linkList, list of clickable links
	 */
	public static function parseLinks( $text ) {
		# Don't let this get flooded
		$max = 10;
		$count = 0;

		$linkList = '';
		# Normalize space characters
		$text = str_replace( [ "\r", "\t" ], [ "\n", " " ], htmlspecialchars( $text ) );
		# Split out each line as a link
		$lines = explode( "\n", $text );
		foreach ( $lines as $line ) {
			$links = explode( " ", $line, 2 );
			$link = $links[0];
			# Any explanation text is not part of the link...
			$extra = isset( $links[1] ) ? ' ' . $links[1] : '';
			if ( strpos( $link, '.' ) ) {
				// @FIXME: other protocals
				$link = ( strpos( $link, 'http://' ) === false ) ? 'http://' . $link : $link;
				$linkList .= "<li><a href='$link'>$link</a>$extra</li>\n";
			}
			$count++;
			if ( $count >= $max ) {
				break;
			}
		}
		if ( $linkList == '' ) {
			$linkList = wfMessage( 'confirmaccount-none-p' )->escaped();
		} else {
			$linkList = "<ul>{$linkList}</ul>";
		}
		return $linkList;
	}

	/**
	 * @param $submitType string
	 * @param $name string User name
	 * @param $errors array
	 */
	protected function showSuccess( $submitType, $name = null, $errors = [] ) {
		$out = $this->getOutput();

		$out->setPageTitle( $this->msg( 'actioncomplete' )->escaped() );
		if ( $this->submitType == 'accept' ) {
			$out->addWikiMsg( 'confirmaccount-acc', $name );
		} elseif ( $this->submitType == 'reject' || $this->submitType == 'spam' ) {
			$out->addWikiMsg( 'confirmaccount-rej' );
		} else {
			$out->redirect( $this->getFullTitle()->getFullUrl() );
			return;
		}
		# Output any errors
		foreach ( $errors as $error ) {
			$out->addHTML( '<p>' . $error . '</p>' );
		}
		# Give link to see other requests
		$out->returnToMain( true, $this->getFullTitle() );
	}

	protected function showList() {
		$out = $this->getOutput();

		# Output the list
		$pager = new ConfirmAccountsPager( $this, [],
			$this->queueType, $this->showRejects, $this->showHeld, $this->showStale );

		if ( $pager->getNumRows() ) {
			if ( $this->showStale ) {
				$out->addWikiMsg( 'confirmaccount-list3' );
			} elseif ( $this->showRejects ) {
				$out->addWikiMsg( 'confirmaccount-list2' );
			} else {
				$out->addWikiMsg( 'confirmaccount-list' );
			}
			$out->addHTML( $pager->getNavigationBar() );
			$out->addHTML( $pager->getBody() );
			$out->addHTML( $pager->getNavigationBar() );
		} else {
			if ( $this->showRejects ) {
				$out->addWikiMsg( 'confirmaccount-none-r' );
			} elseif ( $this->showStale ) {
				$out->addWikiMsg( 'confirmaccount-none-e' );
			} elseif ( $this->showHeld ) {
				$out->addWikiMsg( 'confirmaccount-none-h' );
			} else {
				$out->addWikiMsg( 'confirmaccount-none-o' );
			}
		}

		# Every 30th view, prune old deleted items
		if ( 0 == mt_rand( 0, 5 ) ) {
			ConfirmAccount::runAutoMaintenance();
		}
	}

	/**
	 * @param $row
	 * @return string
	 */
	public function formatRow( $row ) {
		global $wgMemc;

		$linkRenderer = $this->getLinkRenderer();

		if ( $this->showRejects || $this->showStale ) {
			$link = $linkRenderer->makeKnownLink(
				$this->getFullTitle(),
				$this->msg( 'confirmaccount-review' ),
				[],
				[ 'acrid' => (int)$row->acr_id, 'wpShowRejects' => 1 ] );
		} else {
			$link = $linkRenderer->makeKnownLink(
				$this->getFullTitle(),
				$this->msg( 'confirmaccount-review' ),
				[],
				[ 'acrid' => (int)$row->acr_id ] );
		}
		$time = $this->getLanguage()->timeanddate( wfTimestamp( TS_MW, $row->acr_registration ), true );

		$r = "<li class='mw-confirmaccount-type-{$this->queueType}'>";

		$r .= $time . " (<strong>{$link}</strong>)";
		# Auto-rejected accounts have a user ID of zero
		if ( $row->acr_rejected && $row->acr_user ) {
			$datim = $this->getLanguage()->timeanddate( wfTimestamp( TS_MW, $row->acr_rejected ), true );
			$date = $this->getLanguage()->date( wfTimestamp( TS_MW, $row->acr_rejected ), true );
			$time = $this->getLanguage()->time( wfTimestamp( TS_MW, $row->acr_rejected ), true );
			$r .= ' <b>' . $this->msg(
				'confirmaccount-reject', $row->user_name, $datim, $date, $time
			)->parse() . '</b>';
		} elseif ( $row->acr_held && !$row->acr_rejected ) {
			$datim = $this->getLanguage()->timeanddate( wfTimestamp( TS_MW, $row->acr_held ), true );
			$date = $this->getLanguage()->date( wfTimestamp( TS_MW, $row->acr_held ), true );
			$time = $this->getLanguage()->time( wfTimestamp( TS_MW, $row->acr_held ), true );
			$r .= ' <b>' . $this->msg(
				'confirmaccount-held', User::whoIs( $row->acr_user ), $datim, $date, $time
			)->parse() . '</b>';
		}
		# Check if someone is viewing this request
		$key = wfMemcKey( 'acctrequest', 'view', $row->acr_id );
		$value = $wgMemc->get( $key );
		if ( $value ) {
			$r .= ' <b>' . $this->msg( 'confirmaccount-viewing', User::whoIs( $value ) )->parse() . '</b>';
		}

		$r .= "<br /><table class='mw-confirmaccount-body-{$this->queueType}'
			style='border-spacing:1px; padding:3px; border:1px; width:100%;''>";
		if ( $this->hasItem( 'UserName' ) ) {
			$r .= '<tr><td><strong>' . $this->msg(
				'confirmaccount-name'
			)->escaped() . '</strong></td><td width=\'100%\'>' .
				htmlspecialchars( $row->acr_name ) . '</td></tr>';
		}
		if ( $this->hasItem( 'RealName' ) ) {
			$hasCV = $row->acr_filename
				? ' <strong>' . $this->msg( 'confirmaccount-withcv' )->escaped() . '</strong>'
				: '';
			$r .= '<tr><td><strong>' . $this->msg(
				'confirmaccount-real-q'
			)->escaped() . '</strong></td><td width=\'100%\'>' .
				htmlspecialchars( $row->acr_real_name ) . $hasCV . '</td></tr>';
		}
		$econf = $row->acr_email_authenticated
			? ' <strong>' . $this->msg( 'confirmaccount-econf' )->escaped() . '</strong>'
			: '';
		$r .= '<tr><td><strong>' . $this->msg(
			'confirmaccount-email-q'
		)->escaped() . '</strong></td><td width=\'100%\'>' .
			htmlspecialchars( $row->acr_email ) . $econf . '</td></tr>';
		# Truncate this, blah blah...
		$bio = htmlspecialchars( $row->acr_bio );
		$preview = $this->getLanguage()->truncate( $bio, 400, '' );
		if ( strlen( $preview ) < strlen( $bio ) ) {
			$preview = substr( $preview, 0, strrpos( $preview, ' ' ) );
			$preview .= " . . .";
		}
		$r .= '<tr><td><strong>' . $this->msg( 'confirmaccount-bio-q' )->escaped() .
			'</strong></td><td style="width:100%;"><i>' . $preview . '</i></td></tr>';
		$r .= '</table>';

		$r .= '</li>';

		return $r;
	}

	protected function getGroupName() {
		return 'users';
	}
}

/**
 * Query to list out pending accounts
 */
class ConfirmAccountsPager extends ReverseChronologicalPager {
	public $mForm, $mConds;

	function __construct(
		$form, $conds, $type, $rejects = false, $showHeld = false, $showStale = false
	) {
		$this->mForm = $form;
		$this->mConds = $conds;

		$this->mConds['acr_type'] = $type;

		$this->rejects = $rejects;
		$this->stale = $showStale;
		if ( $rejects || $showStale ) {
			$this->mConds['acr_deleted'] = 1;
		} else {
			$this->mConds['acr_deleted'] = 0;
			if ( $showHeld ) {
				$this->mConds[] = 'acr_held IS NOT NULL';
			} else {
				$this->mConds[] = 'acr_held IS NULL';
			}

		}
		parent::__construct();
		# Treat 20 as the default limit, since each entry takes up 5 rows.
		$urlLimit = $this->mRequest->getInt( 'limit' );
		$this->mLimit = $urlLimit ? $urlLimit : 20;
	}

	/**
	 * @return Title
	 */
	function getTitle() {
		return $this->mForm->getFullTitle();
	}

	/**
	 * @param $row
	 * @return string
	 */
	function formatRow( $row ) {
		return $this->mForm->formatRow( $row );
	}

	/**
	 * @return string
	 */
	function getStartBody() {
		if ( $this->getNumRows() ) {
			return '<ul>';
		} else {
			return '';
		}
	}

	/**
	 * @return string
	 */
	function getEndBody() {
		if ( $this->getNumRows() ) {
			return '</ul>';
		} else {
			return '';
		}
	}

	/**
	 * @return array
	 */
	function getQueryInfo() {
		$conds = $this->mConds;
		$tables = [ 'account_requests' ];
		$fields = [ 'acr_id', 'acr_name', 'acr_real_name', 'acr_registration', 'acr_held',
			'acr_user', 'acr_email', 'acr_email_authenticated', 'acr_bio', 'acr_notes',
			'acr_urls', 'acr_filename', 'acr_type', 'acr_rejected' ];
		# Stale requests have a user ID of zero
		if ( $this->stale ) {
			$conds[] = 'acr_user = 0';
		} elseif ( $this->rejects ) {
			$conds[] = 'acr_user != 0';
			$tables[] = 'user';
			$conds[] = 'acr_user = user_id';
			$fields[] = 'user_name';
			$fields[] = 'acr_rejected';
		}
		return [
			'tables' => $tables,
			'fields' => $fields,
			'conds' => $conds
		];
	}

	/**
	 * @return string
	 */
	function getIndexField() {
		return 'acr_registration';
	}
}
