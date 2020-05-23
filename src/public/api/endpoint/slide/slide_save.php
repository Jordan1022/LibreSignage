<?php
/** \file
*
* Save a slide. Whether a user is allowed to access this
* API endpoint depends on the parameters passed to the
* endpoint.
*
* Permissions
*  * \c id != null
*    * Allow if the caller is in the admin group or the caller
*      is the owner of the slide and is in the editor group.
*    * Otherwise allow restricted access if the caller is in
*      the collaborators array of the slide. In this case the
*      collaborators parameter of the API call is silently discarded.
*  * \c id == null
*    * Allow if the caller is in the admin or editor groups.
*
* This endpoint only allows slide modification if the caller
* has locked the slide by calling \c slide_lock_acquire.php first.
* If the slide is not locked or is locked by someone else, the
* '424 Failed Dependency' status code is returned. If a new
* slide is created, the slide is automatically locked for the
* caller.
*
* @par Note!
* This endpoint accepts a few unused parameters to simplify
* implementing the client interface for this endpoint.
* Specifically, clients *must* be able to send all data
* received from \c slide_get.php back to this endpoint, even if
* it's not actually used. This makes it possible to implement
* a simple Object Oriented interface that just sends all data
* fields in the client side Slide object to this endpoint
* without filtering what can be sent.
*
* @method{POST}
* @auth{By token}
* @groups{admin|editor}
* @ratelimit_yes
*
* @request_start{application/json}
* @request{string,id,The ID of the slide to modify or NULL for new.,optional}
* @request{string,name,The new name of the slide.,required}
* @request{int,index,The new 0-based index of the slide.,required}
* @request{int,duration,The new slide duration in seconds.,required}
* @request{string,markup,The new markup of the slide.,required}
* @request{bool,enabled,Whether the slide is enabled or not.,required}
* @request{bool,sched,Whether scheduling is enabled or not.,required}
* @request{int,sched_t_s,The start unix timestamp of scheduling.,required}
* @request{int,sched_t_e,The end unix timestamp of scheduling.,required}
* @request{int,animation,The new ID of the slide transition animation.,required}
* @request{array,collaborators,An array of collaborator usernames.,required}
* @request{mixed,owner,Ignored.,optional}
* @request{mixed,lock,Ignored.,optional}
* @request{mixed,assets,Ignored.,optional}
* @request{mixed,ref_count,Ignored.,optional}
* @request_end
*
* @response_start{application/json}
* @response{Slide,slide,The saved slide object.}
* @response_end
*
* @status_start
* @status{200,On success.}
* @status{400,If the request parameters are invalid.}
* @status{401,If the caller is not allowed to modify or create a slide.}
* @status{403,If the slide quota was reached.}
* @status{404,If the id parameter is defined and no such slide exists.}
* @status{424,If the slide corresponding to id is not locked.}
* @status_end
*/

namespace libresignage\api\endpoint\slide;

require_once($_SERVER['DOCUMENT_ROOT'].'/../common/php/Config.php');

use libresignage\api\APIEndpoint;
use libresignage\api\APIException;
use libresignage\api\HTTPStatus;
use libresignage\common\php\slide\Slide;
use libresignage\common\php\slide\exceptions\SlideNotFoundException;
use libresignage\common\php\auth\User;
use libresignage\common\php\auth\exceptions\UserNotFoundException;
use libresignage\common\php\auth\Session;
use libresignage\common\php\Log;

APIEndpoint::POST(
	[
		'APIAuthModule' => [
			'cookie_auth' => FALSE
		],
		'APIRateLimitModule' => [],
		'APIJSONValidatorModule' => [
			'schema' => [
				'type' => 'object',
				'properties' => [
					'id' => ['type' => ['string', 'null']],
					'name' => ['type' => 'string'],
					'markup' => ['type' => 'string'],
					'duration' => ['type' => 'integer'],
					'enabled' => ['type' => 'boolean'],
					'sched' => ['type' => 'boolean'],
					'sched_t_s' => ['type' => 'integer'],
					'sched_t_e' => ['type' => 'integer'],
					'animation' => ['type' => 'integer'],
					'collaborators' => [
						'type' => 'array',
						'items' => [
							'type' => 'string'
						]
					],
					'owner' => [], // ignored
					'lock' => [], // ignored
					'assets' => [], // ignored
					'ref_count' => [] // ignored
				],
				'required' => [
					'name',
					'markup',
					'duration',
					'enabled',
					'sched',
					'sched_t_s',
					'sched_t_e',
					'animation',
					'collaborators'
				]
			]
		]
	],
	function($req, $module_data) {
		$params = $module_data['APIJSONValidatorModule'];
		$session = $module_data['APIAuthModule']['session'];
		$caller = $module_data['APIAuthModule']['user'];
		$slide = new Slide();

		/*
		* Check permissions and route the API call to the correct
		* handler function.
		*/
		if (property_exists($params, 'id') && $params->id !== NULL) {
			try {
				$slide->load($params->id);
			} catch (SlideNotFoundException $e) {
				throw new APIException(
					"Slide '{$params->id}' doesn't exist.",
					HTTPStatus::NOT_FOUND,
					$e
				);
			}

			if (
				$caller->is_in_group('admin')
				|| (
					$caller->is_in_group('editor')
					&& $caller->get_name() === $slide->get_owner()
				)
			) {
				// admin or editor+owner => ALLOW modifying.
				return ['slide' => modify_slide($session, $slide, $params, TRUE)];
			} else if (
				$caller->is_in_group('editor')
				&& in_array($caller->get_name(), $slide->get_collaborators())
			) {
				// Restricted modification permissions for collaborators.
				return ['slide' => modify_slide($session, $slide, $params, FALSE)];
			} else {
				throw new APIException(
					'User not authorized to do this operation.',
					HTTPStatus::UNAUTHORIZED
				);
			}
		} else if ($caller->is_in_group(['admin', 'editor'])) {
			// admin or editor => ALLOW creation.
			return ['slide' => create_slide($caller, $session, $slide, $params)];
		} else {
			throw new APIException(
				'User not authorized to do this operation.',
				HTTPStatus::UNAUTHORIZED
			);
		}
	}
);

/**
* Set the slide data of $slide.
*
* @param Slide  $slide The Slide to modify.
* @param object $data  The data fields of the Slide.
* @param bool   $owner TRUE if Slide owner called the endpoint, FALSE otherwise.
*/
function set_slide_data(Slide $slide, $data, bool $owner) {
	if ($owner === TRUE) {
		// Set 'collaborators' if the caller is the owner of the Slide.
		try {
			$slide->set_collaborators($data->collaborators);
		} catch (UserNotFoundException $e) {
			throw new APIException(
				$e->getMessage(),
				HTTPStatus::NOT_FOUND
			);
		}
	}

	$slide->set_name($data->name);
	$slide->set_duration($data->duration);
	$slide->set_markup($data->markup);
	$slide->set_enabled($data->enabled);
	$slide->set_sched($data->sched);
	$slide->set_sched_t_s($data->sched_t_s);
	$slide->set_sched_t_e($data->sched_t_e);
	$slide->set_animation($data->animation);
	$slide->update_sched_enabled();
}

/**
* Handler function for creating a new slide as $slide.
*
* This function automatically locks the new Slide for $session.
*
* @param User    $caller  The User object calling this endpoint.
* @param Session $session The Session object of the caller.
* @param Slide   $slide   The Slide to modify.
* @param object  $data    The data fields of the Slide.
*
* @returns array The exported Slide data.
*/
function create_slide(User $caller, Session $session, Slide $slide, $data) {
	if (!$caller->get_quota()->has_quota('slides')) {
		throw new APIException(
			'Slide quota exceeded.',
			HTTPStatus::FORBIDDEN
		);
	}

	try {
		$slide->gen_id();
		$slide->set_owner($caller->get_name());
		$slide->lock_acquire($session);
		set_slide_data($slide, $data, TRUE);
	} catch (\Exception $e) {
		// Cleanup in case of an exception.
		$slide->remove();

		throw $e;
	}

	$caller->get_quota()->use_quota('slides');
	$caller->write();

	return finish_slide($slide);
}

/**
* Handler function for modifying $slide.
*
* @param User    $caller  The User object calling this endpoint.
* @param Session $session The Session object of the caller.
* @param Slide   $slide   The Slide to modify.
* @param object  $data    The data fields of the Slide.
*
* @returns array The exported Slide data.
*/
function modify_slide(Session $session, Slide $slide, $data, bool $owner) {
	if (!$slide->is_locked_by($session)) {
		throw new APIException(
			'Slide not locked by the calling session.',
			HTTPStatus::FAILED_DEPENDENCY
		);
	}

	set_slide_data($slide, $data, $owner);

	return finish_slide($slide);
}

/**
* Handler function for finishing $slide.
*
* @param User    $caller  The User object calling this endpoint.
* @param Session $session The Session object of the caller.
* @param Slide   $slide   The Slide to modify.
* @param object  $data    The data fields of the Slide.
*
* @returns array The exported Slide data.
*/
function finish_slide(Slide $slide) {
	$slide->write();
	return $slide->export(FALSE, FALSE);
}
