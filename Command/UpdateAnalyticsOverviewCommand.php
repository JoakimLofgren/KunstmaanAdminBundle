<?php

namespace Kunstmaan\AdminBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Kunstmaan\AdminBundle\Entity\GoogleClientHelper;
use Kunstmaan\AdminBundle\Entity\GoogleAnalyticsHelper;


class UpdateAnalyticsOverviewCommand extends ContainerAwareCommand {

    protected function configure()
    {
        $this
            ->setName('kuma:ga:update')
            ->setDescription('Update Google Analytics overviews')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // get API client credentials
        $clientId       = $this->getContainer()->getParameter('google.api.client_id');
        $clientSecret   = $this->getContainer()->getParameter('google.api.client_secret');
        $redirectUri    = $this->getContainer()->getParameter('google.api.redirect_uri');
        $devKey         = $this->getContainer()->getParameter('google.api.dev_key');

        // create API client
        $googleClientHelper = new GoogleClientHelper($clientId, $clientSecret, $redirectUri, $devKey, $this->getContainer()->get('doctrine')->getEntityManager());
        $googleClient = $googleClientHelper->getClient();

        if ($googleClientHelper->tokenIsSet()) {
            // create API Analytics helper to execute queries
            $analyticsHelper = new GoogleAnalyticsHelper($googleClient);

            // get entitymanager and load all Analytics Overviews
            $em = $this->getContainer()->get('doctrine')->getEntityManager();
            $overviews = $em->getRepository('KunstmaanAdminBundle:AnalyticsOverview')->getAll();

            // get data for each overview
            foreach ($overviews as $overview) {
                $output->writeln('Getting data for overview "' .$overview->getTitle(). '"');

                // normal metrics
                $results = $analyticsHelper->getResults($overview->getTimespan(), 'ga:visits,ga:pageviews');
                $rows = $results->getRows();

                    // visits metric
                    $visits = $rows[0][0];
                    $overview->setVisits($visits);

                    // pageviews metric
                    $pageviews = $rows[0][1];
                    $overview->setPageViews($pageviews);

                // visitor types
                $results = $analyticsHelper->getResults($overview->getTimespan(), 'ga:visits', ['dimensions' => 'ga:visitorType']);
                $rows = $results->getRows();

                    // new visitors
                    $overview->setNewVisits($rows[0][1]);

                    // returning visitors
                    $overview->setReturningVisits($rows[1][1]);

                // traffic sources
                $results = $analyticsHelper->getResults($overview->getTimespan(), 'ga:visits', ['dimensions' => 'ga:medium', 'sort' => 'ga:medium']);
                $rows = $results->getRows();

                foreach($rows as $row) {
                    switch ($row[0]) {

                        case '(none)': // direct traffic
                            $overview->setTrafficDirect($row[1]);
                            break;

                        case 'organic': // search engine traffic
                            $overview->setTrafficSearchEngine($row[1]);
                            break;

                        case 'referral': // referral traffic
                            $overview->setTrafficReferral($row[1]);
                            break;

                        default: // TODO other referral types? https://developers.google.com/analytics/devguides/reporting/core/dimsmets#view=detail&group=traffic_sources&jump=ga_medium
                            break;
                    }
                }

                // top referral sites
                $results = $analyticsHelper->getResults($overview->getTimespan(), 'ga:visits', ['dimensions' => 'ga:source', 'sort' => '-ga:visits', 'filters' => 'ga:medium==referral']);
                $rows = $results->getRows();

                    // #1 referral
                    $overview->setTopReferralFirst(isset($rows[0][0]) ? $rows[0][0] : '');
                    $overview->setTopReferralFirstValue(isset($rows[0][1]) ? $rows[0][1] : 0);

                    // #2 referral
                    $overview->setTopReferralSecond(isset($rows[1][0]) ? $rows[1][0] : '');
                    $overview->setTopReferralSecondValue(isset($rows[1][1]) ? $rows[1][1] : 0);

                    // #3 referral
                    $overview->setTopReferralThird(isset($rows[2][0]) ? $rows[2][0] : '');
                    $overview->setTopReferralThirdValue(isset($rows[2][1]) ? $rows[2][1] : 0);

                // top searches
                $results = $analyticsHelper->getResults($overview->getTimespan(), 'ga:searchUniques', ['dimensions' => 'ga:searchKeyword', 'sort' => '-ga:searchUniques']);
                $rows = $results->getRows();

                    // #1 search
                    $overview->setTopSearchFirst(isset($rows[0][0]) ? $rows[0][0] : '');
                    $overview->setTopSearchFirstValue(isset($rows[0][1]) ? $rows[0][1] : 0);

                    // #2 search
                    $overview->setTopSearchSecond(isset($rows[1][0]) ? $rows[1][0] : '');
                    $overview->setTopSearchSecondValue(isset($rows[1][1]) ? $rows[1][1] : 0);

                    // #3 search
                    $overview->setTopSearchThird(isset($rows[2][0]) ? $rows[2][0] : '');
                    $overview->setTopSearchThirdValue(isset($rows[2][1]) ? $rows[2][1] : 0);

                // persist entity back to DB
                $em->persist($overview);
            }

            $em->flush();
            $output->writeln('Google Analytics data succesfully updated'); // done
        } else {
            $output->writeln('You haven\'t configured a Google account yet, or the token is invalid'); // error
        }

    }
}