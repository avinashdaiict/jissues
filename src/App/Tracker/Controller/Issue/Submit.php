<?php
/**
 * Part of the Joomla Tracker's Tracker Application
 *
 * @copyright  Copyright (C) 2012 - 2014 Open Source Matters, Inc. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

namespace App\Tracker\Controller\Issue;

use App\Tracker\Model\CategoryModel;
use App\Tracker\Model\IssueModel;

use Joomla\Date\Date;

use JTracker\Controller\AbstractTrackerController;

/**
 * Add issues controller class.
 *
 * @since  1.0
 */
class Submit extends AbstractTrackerController
{
	/**
	 * Execute the controller.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 * @throws  \Exception
	 */
	public function execute()
	{
		/* @type \JTracker\Application $application */
		$application = $this->getContainer()->get('app');

		$user = $application->getUser();

		$user->authorize('create');

		/* @type \Joomla\Github\Github $gitHub */
		$gitHub = $this->getContainer()->get('gitHub');

		$project = $application->getProject();

		$body = $application->input->get('body', '', 'raw');

		// Prepare issue for the store
		$data = array();

		$data['title'] = $application->input->getString('title');

		if (!$body)
		{
			throw new \Exception('No body received.');
		}

		$issueModel = new IssueModel($this->getContainer()->get('db'));
		$issueModel->setProject($project);

		if ($project->gh_user && $project->gh_project)
		{
			// Project is managed on GitHub
			try
			{
				$gitHubResponse = $gitHub->issues->create(
					$project->gh_user, $project->gh_project,
					$data['title'], $body
				);
			}
			catch (\Exception $e)
			{
				$this->getContainer()->get('app')->getLogger()->error(
					sprintf(
						'Error code %1$s received from GitHub when creating an issue with the following data:'
						. ' GitHub User: %2$s; GitHub Repo: %3$s; Title: %4$s; Body Text: %5$s'
						. '  The error message returned was: %6$s',
						$e->getCode(),
						$project->gh_user,
						$project->gh_project,
						$data['title'],
						$body,
						$e->getMessage()
					)
				);

				throw $e;
			}

			if (!isset($gitHubResponse->id))
			{
				throw new \Exception('Invalid response from GitHub');
			}

			$data['opened_date']   = $gitHubResponse->created_at;
			$data['modified_date'] = $gitHubResponse->created_at;
			$data['opened_by']     = $gitHubResponse->user->login;
			$data['modified_by']   = $gitHubResponse->user->login;
			$data['number']        = $gitHubResponse->number;

			$data['description'] = $gitHub->markdown->render(
				$body, 'gfm',
				$project->gh_user . '/' . $project->gh_project
			);
		}
		else
		{
			// Project is managed by JTracker only
			$data['opened_date']    = (new Date)->format($this->getContainer()->get('db')->getDateFormat());
			$data['modified_date']  = (new Date)->format($this->getContainer()->get('db')->getDateFormat());
			$data['opened_by']      = $user->username;
			$data['modified_by']    = $user->username;
			$data['number']         = $issueModel->getNextNumber();
			$data['description'] = $gitHub->markdown->render($body, 'markdown');
		}

		$data['priority']        = $application->input->getInt('priority');
		$data['milestone_id']    = $application->input->getInt('milestone_id');
		$data['build']           = $application->input->getString('build');
		$data['project_id']      = $project->project_id;
		$data['issue_number']    = $data['number'];
		$data['description_raw'] = $body;

		// Store the issue
		try
		{
			// Save the issues and Get the issue id from model state
			$issue_id = $issueModel->add($data)->getState()->get('issue_id');

			// Save the category for the issue
			$category['issue_id']   = $issue_id;
			$category['categories'] = $application->input->get('categories', null, 'array');
			$categoryModel = new CategoryModel($this->getContainer()->get('db'));
			$categoryModel->saveCategory($category);
		}
		catch (\Exception $e)
		{
			$application->enqueueMessage($e->getMessage(), 'error');

			$application->redirect(
				$application->get('uri.base.path')
				. 'tracker/' . $project->alias . '/add'
			);
		}

		$application->enqueueMessage(g11n3t('Your report has been submitted.'), 'success');

		$application->redirect(
			$application->get('uri.base.path')
			. 'tracker/' . $project->alias . '/' . $data['number']
		);

		return;
	}
}
