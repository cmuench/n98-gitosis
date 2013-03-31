<?php
/**
 * Copyright (c) 2012 Chistian Münch
 *
 * https://github.com/cmuench/n98-gitosis-admin
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
 * OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE
 *
 * @author Christian Münch <christian@muench-worms.de>
 */

namespace N98\Gitosis\Config;

use Zend\Config\Config as ZendConfig;
use Zend\Config\Writer\Ini as Writer;
use Gitter\Client as GitClient;
use Symfony\Component\Finder\Finder;

class Config
{
    /**
     * @var string
     */
    protected $filename;

    /**
     * @var array
     */
    protected $gitosis;

    /**
     * @var array
     */
    protected $repos = array();

    /**
     * @var array
     */
    protected $groups = array();

    public function __construct($filename)
    {
        $this->filename = $filename;
        $this->readFile();
    }

    protected function readFile()
    {
        if (!file_exists($this->filename)) {
            throw new \RuntimeException('Gitosis config "' . $this->filename . '" file does not exist.');
        }

        $data = parse_ini_file($this->filename, true, INI_SCANNER_RAW);

        foreach ($data as $sectionName => $sectionData) {
            if ($sectionName == 'gitosis') {
                $sectionType = $sectionTypeName = 'gitosis';
            } else {
                list($sectionType, $sectionTypeName) = explode(' ', $sectionName);
            }

            switch ($sectionType) {
                case 'gitosis':
                    $this->gitosis = new Gitosis($sectionTypeName, $sectionData);
                    break;

                case 'repo':
                    $this->addRepository(new Repository($sectionTypeName, $sectionData));
                    break;

                case 'group':
                    $this->addGroup(new Group($sectionTypeName, $sectionData));
                    break;

                default:
            }
        }
        $this->addImplicitReposFromGroups();


        ksort($this->repos);
        ksort($this->groups);
    }

    /**
     * Adds repositories from group entries without an explicit repository section
     */
    protected function addImplicitReposFromGroups()
    {
        $implicitRepos = array();
        foreach ($this->groups as $group) {
            $implicitRepos = array_merge($group->getWritable(), $group->getReadonly(), $implicitRepos);
        }
        foreach ($implicitRepos as $implicitRepo) {
            if (!isset($this->repos[$implicitRepo])) {
                $this->repos[$implicitRepo] = new Repository($implicitRepo);
            }
        }
    }

    /**
     * @param Group $group
     * @return Config
     */
    public function addGroup(Group $group)
    {
        $this->groups[$group->getName()] = $group;

        return $this;
    }

    /**
     * @return array
     */
    public function getGroups()
    {
        return $this->groups;
    }

    /**
     * @param string $groupName
     * @return Config
     */
    public function removeGroup($groupName)
    {
        if (isset($this->groups[$groupName])) {
            unset($this->groups[$groupName]);
        }

        return $this;
    }

    /**
     * Returns a single group by name
     *
     * @param string $name
     * @return Group
     */
    public function getGroup($name)
    {
        if (!isset($this->groups[$name])) {
            throw new \InvalidArgumentException('Group does not exist');
        }
        return $this->groups[$name];
    }

    /**
     * @param Repository $repo
     * @return Config
     */
    public function addRepository(Repository $repo)
    {
        $this->repos[$repo->getName()] = $repo;

        return $this;
    }

    /**
     * Deletes a repository from config and also removes access from every group
     *
     * @param string $repoName
     * @return Config
     */
    public function removeRepository($repoName)
    {
        if (isset($this->repos[$repoName])) {
            unset($this->repos[$repoName]);
            foreach ($this->groups as $group) { /* @var $group Group */
                $group->removeRepositoryAccess($repoName);
            }
        }

        return $this;
    }

    /**
     * @return array
     */
    public function getRepositories()
    {
        return $this->repos;
    }

    /**
     * Returns a single repository by name
     *
     * @param string $name
     * @return Repository
     */
    public function getRepository($name)
    {
        if (!isset($this->repos[$name])) {
            throw new \InvalidArgumentException('Repository does not exist');
        }
        return $this->repos[$name];
    }

    /**
     * Returns a list of all users across all groups
     *
     * @return array
     */
    public function getUsers()
    {
        $users = array();
        foreach ($this->getGroups() as $group) {
            $users = array_merge($users, $group->getMembers());
        }
        $users = array_unique($users);
        $users = array_filter($users, function($var) {
            return substr($var, 0, 1) != '@';
        });

        /**
         * Load ssh keys. A user must not be assigned to a user group
         */
        $sshKeyList = $this->getSshKeyList();
        $sshKeyList = array_map(function($value) {
            return substr($value, 0, -4);
        }, $sshKeyList);
        $users = array_unique(array_merge($users, $sshKeyList));

        sort($users);

        return $users;
    }

    /**
     * Returns a list of groups in which a user are
     *
     * @param string $username
     * @return array[Group]
     */
    public function getGroupsByUsername($username)
    {
        $groups = array();
        foreach ($this->groups as $group) { /* @var $group Group */
            if ($group->isMember($username)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * List of key basenames
     *
     * @return array[string]
     */
    public function getSshKeyList()
    {
        $finder = new Finder();
        $finder->files()
               ->in($this->getGitosisKeyDir())
               ->name('*.pub')
               ->size('> 0')
               ->sortByName();

        $files = array();
        foreach ($finder as $file) {
            $files[] = $file->getFilename();
        }

        return $files;
    }

    /**
     * Creates or overwrites a ssh key file
     *
     * @param string $username
     * @param string $sshKeyContent
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     * @return Config
     */
    public function saveSshKey($username, $sshKeyContent)
    {
        if (empty($sshKeyContent)) {
            throw new \InvalidArgumentException('Cannot write empty ssh key');
        }

        $sshKeyFilename = $this->getSshKeyFilename($username);

        if ($this->sshKeyExists($username) && !is_writable($sshKeyFilename)) {
            // Key eixsts -> We need write access to key file
            throw new \RuntimeException('Cannot save ssh key file. No write acccess to file');
        } else {
            // Key does not exist -> We must have write access to keydir
            if (!is_writable(dirname($sshKeyFilename))) {
                throw new \RuntimeException('Cannot save ssh key file. No write acccess to keydir');
            }
        }

        file_put_contents($sshKeyFilename, $sshKeyContent);

        $this->getGitRepository()->add($sshKeyFilename);

        return $this;
    }

    /**
     * @param string $username
     * @return string
     */
    public function getSshKeyContent($username)
    {
        if ($this->sshKeyExists($username)) {
            return file_get_contents($this->getSshKeyFilename($username));
        }

        return '';
    }

    /**
     * Check if a user already exists
     *
     * @param string $username
     * @return bool
     */
    public function userExists($username)
    {
        $users = $this->getUsers();
        return in_array($username, $users);
    }

    /**
     * @param string $username
     * @return bool
     */
    public function userNotExists($username)
    {
        return !$this->userExists($username);
    }

    /**
     * @param string $username
     * @param bool $removeKey
     * @throws \RuntimeException
     * @return Config
     */
    public function removeUser($username, $removeKey = true)
    {
        if ($removeKey) {
            if ($this->sshKeyExists($username)) {
                $keyFile = $this->getSshKeyFilename($username);
                if (is_writable($keyFile)) {
                    unlink($keyFile);
                } else {
                    throw new \RuntimeException('Key file cannot be removed. No write access');
                }
            }
        }

        foreach ($this->groups as $group) { /* @var $group Group */
            $group->removeUser($username);
        }

        return $this;
    }

    /**
     * Returns a list of users which can write
     *
     * @param string $
     * @return array[Group]
     */
    public function getWritableGroupsByRepository($repositoryName)
    {
        $groups = array();
        foreach ($this->groups as $group) { /* @var $group Group */
            if ($group->hasWriteAccessToRepository($repositoryName)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * Returns a list of users which can access repository readonly
     *
     * @param string $
     * @return array[Group]
     */
    public function getReadonlyGroupsByRepository($repositoryName)
    {
        $groups = array();
        foreach ($this->groups as $group) { /* @var $group Group */
            if ($group->hasReadonlyAccessToRepository($repositoryName)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * @param string $repositoryName
     * @return array[string]
     */
    public function getWritableUsersByRepository($repositoryName)
    {
        $users = array();
        $groups = $this->getWritableGroupsByRepository($repositoryName);
        foreach ($groups as $group) { /* @var $group Group */
            $users = array_merge($users, $group->getMembers());
        }

        return array_unique($users);
    }

    /**
     * @param string $repositoryName
     * @return array[string]
     */
    public function getReadonlyUsersByRepository($repositoryName)
    {
        $users = array();
        $groups = $this->getReadonlyGroupsByRepository($repositoryName);
        foreach ($groups as $group) { /* @var $group Group */
            $users = array_merge($users, $group->getMembers());
        }

        return array_unique($users);
    }

    /**
     * Save config
     *
     * @throws \RuntimeException
     */
    public function save()
    {
        $iniWriter = new Writer();
        $iniWriter->setNestSeparator(null);
        $iniString = $iniWriter->toString($this->buildData());
        $iniString = str_replace('"', '', $iniString);
        if (!is_writable($this->filename)) {
            throw new \RuntimeException('Gitosis config file is not writeable: ' . $this->filename);
        }
        file_put_contents($this->filename, $iniString);
    }

    /**
     * Build array for config file generation
     *
     * @return array
     */
    protected function buildData()
    {
        $config = new ZendConfig(array(), true);

        $config->gitosis = $this->gitosis->toArray();

        foreach ($this->getGroups() as $group) {
            $config->{'group ' . $group->getName()} = $group->toArray();
        }

        foreach ($this->getRepositories() as $repository) {
            $config->{'repo ' . $repository->getName()} = $repository->toArray();
        }

        return $config;
    }

    /**
     * @return string
     */
    public function getGitosisRoot()
    {
        return dirname($this->filename);
    }

    /**
     * @reeturn GitClient
     */
    public function getGitClient()
    {
        return new GitClient();
    }

    /**
     * @return \Gitter\Repository
     */
    public function getGitRepository()
    {
        $client = $this->getGitClient();
        $repository = $client->getRepository($this->getGitosisRoot());

        return $repository;
    }

    /**
     * @return string
     */
    public function getGitosisKeyDir()
    {
        return $this->getGitosisRoot() . DIRECTORY_SEPARATOR . 'keydir';
    }

    /**
     * @param string $username
     */
    public function sshKeyExists($username)
    {
        return file_exists($this->getSshKeyFilename($username));
    }

    /**
     * @param string $username
     * @return string
     */
    public function getSshKeyFilename($username)
    {
        return $this->getGitosisKeyDir() . DIRECTORY_SEPARATOR . $username . '.pub';
    }

    /**
     * Push data to git remote
     */
    public function persist()
    {
        $this->getGitRepository()
             ->add($this->filename)
             ->commit('Updated config')
             ->push();
    }

    /**
     * Revert local changes
     */
    public function revert()
    {
        $this->getGitClient()->run($this->getGitRepository(), 'reset --hard');
    }

}