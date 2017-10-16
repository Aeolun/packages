<?php

/*
 * Copyright (c) Terramar Labs
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace Terramar\Packages\Plugin\GitLab;

use Doctrine\ORM\EntityManager;
use Gitlab\Client;
use Gitlab\HttpClient\Builder;
use Gitlab\Model\Project;
use Nice\Router\UrlGeneratorInterface;
use Terramar\Packages\Entity\Package;
use Terramar\Packages\Entity\Remote;
use Terramar\Packages\Helper\SyncAdapterInterface;

class SyncAdapter implements SyncAdapterInterface
{
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    private $entityManager;

    /**
     * @var \Nice\Router\UrlGeneratorInterface
     */
    private $urlGenerator;

    private $packagesConfiguration;

    /**
     * Constructor.
     *
     * @param EntityManager         $entityManager
     * @param UrlGeneratorInterface $urlGenerator
     */
    public function __construct(EntityManager $entityManager, UrlGeneratorInterface $urlGenerator, $packagesConfiguration)
    {
        $this->entityManager = $entityManager;
        $this->urlGenerator = $urlGenerator;
        $this->packagesConfiguration = $packagesConfiguration;
    }

    /**
     * @param Remote $remote
     *
     * @return bool
     */
    public function supports(Remote $remote)
    {
        return $remote->getAdapter() === $this->getName();
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'GitLab';
    }

    /**
     * @param Remote $remote
     *
     * @return Package[]
     */
    public function synchronizePackages(Remote $remote)
    {
        /** @var []Package $existingPackages */
        $existingPackages = $this->entityManager->getRepository('Terramar\Packages\Entity\Package')->findBy(['remote' => $remote]);

        $projects = $this->getAllProjects($remote);

        $packages = [];
        foreach ($projects as $project) {
            $package = $this->getExistingPackage($existingPackages, $project['id']);
            if ($package === null) {
                $package = new Package();
                $package->setExternalId($project['id']);
                $package->setRemote($remote);
            }
            $package->setName($project['name']);
            $package->setDescription($project['description']);
            $package->setFqn($project['path_with_namespace']);
            $package->setWebUrl($project['web_url']);
            $package->setSshUrl($project['ssh_url_to_repo']);
            $packages[] = $package;
        }

        $removed = array_diff($existingPackages, $packages);
        foreach ($removed as $package) {
            $this->entityManager->remove($package);
        }

        return $packages;
    }

    private function getAllProjects(Remote $remote)
    {
        $client = $this->getClient($remote);

        $user = $client->api('users')->me();
        $isAdmin = isset($user['is_admin']) ? $user['is_admin'] : false;
        $projects = [];
        $page = 1;
        while (true) {
            /*
             * there is a difference when accessing /projects (accessible) and /projects/all (all)
             * http://doc.gitlab.com/ce/api/projects.html
             */
            if ($isAdmin) {
                $visibleProjects = $client->api('projects')->all([
                    'page'     => $page,
                    'per_page' => 100,
                ]);
            } else {
                $visibleProjects = $client->api('projects')->all([
                    'page'       => $page,
                    'per_page'   => 100,
                    'membership' => true,
                ]);
            }

            $projects = array_merge($projects, $visibleProjects);
            $linkHeader = $client->getResponseHistory()->getLastResponse()->getHeader('Link');

            if (strpos($linkHeader[0], 'rel="next"') === false) {
                break;
            }

            ++$page;
        }

        return $projects;
    }

    private function getClient(Remote $remote)
    {
        $config = $this->getRemoteConfig($remote);

        $client = new Client(new Builder());
        $client->setUrl(rtrim($config->getUrl(), '/') . '/api/v4/');
        $client->authenticate($config->getToken(), Client::AUTH_HTTP_TOKEN);

        return $client;
    }

    /**
     * @param Remote $remote
     *
     * @return RemoteConfiguration
     */
    private function getRemoteConfig(Remote $remote)
    {
        return $this->entityManager->getRepository('Terramar\Packages\Plugin\GitLab\RemoteConfiguration')->findOneBy(['remote' => $remote]);
    }

    /**
     * @param $existingPackages
     * @param $gitlabId
     *
     * @return Package|null
     */
    private function getExistingPackage($existingPackages, $gitlabId)
    {
        $res = array_filter($existingPackages, function (Package $package) use ($gitlabId) {
            return (string)$package->getExternalId() === (string)$gitlabId;
        });
        if (count($res) === 0) {
            return null;
        }

        return array_shift($res);
    }

    /**
     * Enable a GitLab webhook for the given Package.
     *
     * @param Package $package
     *
     * @return bool
     */
    public function enableHook(Package $package)
    {
        $config = $this->getConfig($package);
        if ($config->isEnabled()) {
            return true;
        }
        try {
            if ( ! empty($this->packagesConfiguration['hook_base'])) {
                $hookUrl = $this->packagesConfiguration['hook_base'] . $this->urlGenerator->generate('webhook_receive', ['id' => $package->getId()]);
            } else {
                $hookUrl = $this->urlGenerator->generate('webhook_receive', ['id' => $package->getId()], true);
            }

            $client = $this->getClient($package->getRemote());
            $project = Project::fromArray($client, (array)$client->api('projects')->show($package->getExternalId()));
            $hook = $project->addHook(
                $hookUrl,
                ['push_events'     => true,
                 'tag_push_events' => true,
                ]
            );
            $package->setHookExternalId($hook->id);
            $config->setEnabled(true);

            return true;
        } catch (\Exception $e) {
            // TODO: Log the exception
            return false;
        }
    }

    private function getConfig(Package $package)
    {
        return $this->entityManager->getRepository('Terramar\Packages\Plugin\GitLab\PackageConfiguration')->findOneBy(['package' => $package]);
    }

    /**
     * Disable a GitLab webhook for the given Package.
     *
     * @param Package $package
     *
     * @return bool
     */
    public function disableHook(Package $package)
    {
        $config = $this->getConfig($package);
        if ( ! $config->isEnabled()) {
            return true;
        }

        try {
            if ($package->getHookExternalId()) {
                $client = $this->getClient($package->getRemote());
                $project = Project::fromArray($client,
                    (array)$client->api('projects')->show($package->getExternalId()));
                $project->removeHook($package->getHookExternalId());
            }

            $package->setHookExternalId('');
            $config->setEnabled(false);

            return true;
        } catch (\Exception $e) {
            // TODO: Log the exception
            $package->setHookExternalId('');
            $config->setEnabled(false);

            return false;
        }
    }
}
