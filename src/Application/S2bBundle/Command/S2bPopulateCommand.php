<?php

namespace Application\S2bBundle\Command;

use Application\S2bBundle\Document\Bundle;
use Application\S2bBundle\Document\User;
use Symfony\Framework\FoundationBundle\Command\Command as BaseCommand;
use Symfony\Components\Console\Input\InputArgument;
use Symfony\Components\Console\Input\InputOption;
use Symfony\Components\Console\Input\InputInterface;
use Symfony\Components\Console\Output\OutputInterface;
use Symfony\Components\Console\Output\Output;
use Doctrine\Common\Collections\ArrayCollection;

// Require php-github-api
require_once(__DIR__.'/../../../vendor/php-github-api/lib/phpGitHubApi.php');
// Require Goutte
require_once(__DIR__.'/../../../vendor/Goutte/src/Goutte/Client.php');

// Ugly fix to prevent Zend fatal error
set_include_path(get_include_path().PATH_SEPARATOR.realpath(__DIR__.'/../../../vendor/Zend/library'));

/**
 * Update local database from web searches
 */
class S2bPopulateCommand extends BaseCommand
{
    /**
     * @see Command
     */
    protected function configure()
    {
        $this
        ->setDefinition(array())
        ->setName('s2b:populate');
    }

    /**
     * @see Command
     *
     * @throws \InvalidArgumentException When the target directory does not exist
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $github = new \phpGitHubApi();
        $repoSearch = new \Application\S2bBundle\Search\RepoSearch($github, new \Goutte\Client(), $output);
        $githubRepos = $repoSearch->searchBundles(5000, $output);
        $output->writeLn(sprintf('Found %d bundle candidates', count($githubRepos)));

        $dm = $this->container->getDoctrine_odm_mongodb_documentManagerService();
        $existingBundles = $dm->find('Application\S2bBundle\Document\Bundle')->getResults();
        $users = $dm->find('Application\S2bBundle\Document\User')->getResults();
        $validator = $this->container->getValidatorService();
        $bundles = array();
        $counters = array(
            'created' => 0,
            'updated' => 0,
            'removed' => 0
        );

        // first pass, update and revalidate existing bundles
        foreach($existingBundles as $existingBundle) {
            $exists = false;
            foreach($githubRepos as $githubRepo) {
                if($existingBundle->getName() === $githubRepo['name'] && $existingBundle->getUsername() === $githubRepo['username']) {
                    $existingBundle->fromRepositoryArray($githubRepo);
                    $exists = true;
                    ++$counters['updated'];
                    break;
                }
            }
            $existingBundle->setIsOnGithub($exists);
            if($validator->validate($existingBundle)->count()) {
                $dm->remove($existingBundle);
                ++$counters['removed'];
            }
        }
        
        // second pass, create missing bundles
        foreach($githubRepos as $githubRepo) {
            $exists = false;
            foreach($existingBundles as $existingBundle) {
                if($existingBundle->getName() === $githubRepo['name'] && $existingBundle->getUsername() === $githubRepo['username']) {
                    $exists = true;
                    break;
                }
            }
            if(!$exists) {
                $bundle = new Bundle();
                $bundle->fromRepositoryArray($githubRepo);
                $bundle->setIsOnGithub(true);
                $user = null;
                foreach($users as $u) {
                    if($githubRepo['username'] === $u->getName()) {
                        $user = $u;
                        $break;
                    }
                }
                if(!$user) {
                    $user = new User();
                    $user->setName($githubRepo['username']);
                    $users[] = $user;
                }
                $bundle->setUser($user);
                if(!$validator->validate($bundle)->count()) {
                    $user->addBundle($bundle);
                    $dm->persist($bundle);
                    $dm->persist($user);
                    ++$counters['created'];
                }
                else {
                    $output->writeLn('Ignore '.$bundle->getName());
                }
            }
        }

        $dm->flush();

        $output->writeln(sprintf('%d created, %d updated, %d removed', $counters['created'], $counters['updated'], $counters['removed']));

        return;

        // Now update bundles with more precise GitHub data
        $bundles = $dm->find('Application\S2bBundle\Document\Bundle')->getResults();
        foreach($bundles as $bundle) {
            $output->write($bundle->getFullName().str_repeat(' ', 50-strlen($bundle->getFullName())));
            $output->write(' commits');
            $commits = $github->getCommitApi()->getBranchCommits($bundle->getUsername(), $bundle->getName(), 'master');
            if(empty($commits)) {
                $dm->remove($bundle);
                break;
            }
            $bundle->setLastCommits(array_slice($commits, 0, 5));
            $output->write(' readme');
            $blobs = $github->getObjectApi()->listBlobs($bundle->getUsername(), $bundle->getName(), 'master');
            foreach(array('README.markdown', 'README.md', 'README') as $readmeFilename) {
                if(isset($blobs[$readmeFilename])) {
                    $readmeSha = $blobs[$readmeFilename];
                    $readmeText = $github->getObjectApi()->getRawData($bundle->getUsername(), $bundle->getName(), $readmeSha);
                    $bundle->setReadme($readmeText);
                    break;
                }
            }
            $output->write(' tags');
            $tags = $github->getRepoApi()->getRepoTags($bundle->getUsername(), $bundle->getName());
            $bundle->setTags(array_keys($tags));

            $bundle->recalculateScore();
            $output->writeLn(' '.$bundle->getScore());
            sleep(3); // prevent reaching GitHub API max calls (60 per minute)
        }
        
        // Now update users with more precise GitHub data
        $users = $dm->find('Application\S2bBundle\Document\User')->getResults();
        $output->writeLn(array('', sprintf('Will now update %d users', count($users))));
        foreach($users as $user) {
            $output->write($user->getName().str_repeat(' ', 50-strlen($user->getName())));
            $data = $github->getUserApi()->show($user->getName());
            $user->fromUserArray($data);
            $dm->persist($user);
            $output->writeLn('OK');
            sleep(1);
        }

        $dm->flush();
    }
}
