<?php

namespace Oro\Bundle\CronBundle\Command;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Common\Persistence\ObjectRepository;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

use JMS\JobQueueBundle\Entity\Job;

use Oro\Bundle\CronBundle\Entity\Schedule;

class CronCommand extends ContainerAwareCommand
{
    const COMMAND_NAME = 'oro:cron';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName(self::COMMAND_NAME)
            ->setDescription('Cron commands launcher')
            ->addOption(
                'skipCheckDaemon',
                null,
                InputOption::VALUE_NONE,
                'Skipping check daemon is running'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // check for maintenance mode - do not run cron jobs if it is switched on
        if ($this->getContainer()->get('oro_platform.maintenance')->isOn()) {
            $output->writeln('');
            $output->writeln('<error>System is in maintenance mode, aborting</error>');

            return;
        }

        $daemon = $this->getContainer()->get('oro_cron.job_daemon');
        $skipCheckDaemon = $input->getOption('skipCheckDaemon');

        // check if daemon is running
        if (!$skipCheckDaemon && !$daemon->getPid()) {
            $output->writeln('');
            $output->write('Daemon process not found, running.. ');

            if ($pid = $daemon->run()) {
                $output->writeln(sprintf('<info>OK</info> (pid: %u)', $pid));
            } else {
                $output->writeln('<error>failed</error>. Cron jobs can\'t be launched.');

                return;
            }
        }

        $schedules = $this->getAllSchedules();
        $em = $this->getEntityManager('JMSJobQueueBundle:Job');

        $jobs = $this->processCommands($this->getApplication()->all('oro:cron'), $schedules, $output);
        $jobs = array_merge($jobs, $this->processSchedules($schedules, $output));

        array_walk($jobs, [$em, 'persist']);

        $em->flush();

        $output->writeln('');
        $output->writeln('All commands finished');
    }

    /**
     * @param array|Command[]|CronCommandInterface[] $commands
     * @param Collection $schedules
     * @param OutputInterface $output
     *
     * @return array|Job[]
     */
    protected function processCommands(array $commands, Collection $schedules, OutputInterface $output)
    {
        $jobs = [];
        $em = $this->getEntityManager('OroCronBundle:Schedule');

        foreach ($commands as $name => $command) {
            $output->write(sprintf('Processing command "<info>%s</info>": ', $name));

            if ($this->skipCommand($command, $output)) {
                continue;
            }

            $matchedSchedules = $this->matchSchedules($schedules, $name);
            foreach ($matchedSchedules as $schedule) {
                $schedules->removeElement($schedule);
            }

            if (0 === count($matchedSchedules)) {
                $em->persist($this->createSchedule($command, $name, $output));

                continue;
            }

            $schedule = $matchedSchedules->first();
            $this->checkDefinition($command, $schedule);

            if ($job = $this->createJob($output, $schedule, $name)) {
                $jobs[] = $job;
            }
        }

        $em->flush();

        return $jobs;
    }

    /**
     * @param Collection|Schedule[] $schedules
     * @param OutputInterface $output
     *
     * @return array|Job[]
     */
    protected function processSchedules(Collection $schedules, OutputInterface $output)
    {
        $jobs = [];

        foreach ($schedules as $schedule) {
            if (!$this->getApplication()->has($schedule->getCommand())) {
                continue;
            }

            $arguments = $schedule->getArguments() ? ' ' . implode(' ', $schedule->getArguments()) : '';

            $output->write(sprintf('Processing command "<info>%s%s</info>": ', $schedule->getCommand(), $arguments));

            $job = $this->createJob($output, $schedule, $schedule->getCommand());
            if ($job) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /**
     * @param Command $command
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function skipCommand(Command $command, OutputInterface $output)
    {
        if (!$command instanceof CronCommandInterface) {
            $output->writeln(
                '<error>Unable to setup, command must be instance of CronCommandInterface</error>'
            );

            return true;
        }

        if (!$command->getDefaultDefinition()) {
            $output->writeln('<error>no cron definition found, check command</error>');

            return true;
        }

        return false;
    }

    /**
     * @param Collection|Schedule[] $schedules
     * @param string $name
     *
     * @return Collection
     */
    protected function matchSchedules(Collection $schedules, $name)
    {
        return $schedules->filter(
            function (Schedule $schedule) use ($name) {
                return $schedule->getCommand() === $name && $schedule->getArguments() === [];
            }
        );
    }

    /**
     * @param CronCommandInterface $command
     * @param string $name
     * @param OutputInterface $output
     *
     * @return Schedule
     */
    protected function createSchedule(CronCommandInterface $command, $name, OutputInterface $output)
    {
        $output->writeln('<comment>new command found, setting up schedule..</comment>');

        $schedule = new Schedule();
        $schedule
            ->setCommand($name)
            ->setDefinition($command->getDefaultDefinition());

        return $schedule;
    }

    /**
     * @param CronCommandInterface $command
     * @param Schedule $schedule
     */
    protected function checkDefinition(CronCommandInterface $command, Schedule $schedule)
    {
        $defaultDefinition = $command->getDefaultDefinition();
        if ($schedule->getDefinition() !== $defaultDefinition) {
            $schedule->setDefinition($defaultDefinition);
        }
    }

    /**
     * @param OutputInterface $output
     * @param Schedule $schedule
     * @param string $name
     *
     * @return null|Job
     */
    protected function createJob(OutputInterface $output, Schedule $schedule, $name)
    {
        $cron = $this->getContainer()->get('oro_cron.helper.cron')->createCron($schedule->getDefinition());
        $arguments = array_values($schedule->getArguments());

        /**
         * @todo Add "Oro timezone" setting as parameter to isDue method
         */
        if ($cron->isDue()) {
            if (!$this->hasJobInQueue($name, $arguments)) {
                $job = new Job($name, $arguments);

                $output->writeln('<comment>added to job queue</comment>');

                return $job;
            } else {
                $output->writeln('<comment>already exists in job queue</comment>');
            }
        } else {
            $output->writeln('<comment>skipped</comment>');
        }

        return null;
    }

    /**
     * @param string $name
     * @param array $arguments
     *
     * @return bool
     */
    protected function hasJobInQueue($name, array $arguments)
    {
        return $this->getContainer()->get('oro_cron.job_manager')->hasJobInQueue($name, json_encode($arguments));
    }

    /**
     * @param string $className
     * @return ObjectManager
     */
    protected function getEntityManager($className)
    {
        return $this->getContainer()->get('doctrine')->getManagerForClass($className);
    }

    /**
     * @param string $className
     * @return ObjectRepository
     */
    protected function getRepository($className)
    {
        return $this->getEntityManager($className)->getRepository($className);
    }

    /**
     * @return ArrayCollection|Schedule[]
     */
    protected function getAllSchedules()
    {
        return new ArrayCollection($this->getRepository('OroCronBundle:Schedule')->findAll());
    }
}
