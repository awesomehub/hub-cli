<?php
namespace Docklyn;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;
use Docklyn\Exception\ExceptionHandlerManagerInterface;
use Docklyn\Filesystem\Filesystem;
use Docklyn\Exec\Exec;

class Docklyn
{
    const VERSION = '0.1.0';

    /**
     * @var Application
     */
    private $application;

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ExceptionHandlerManagerInterface
     */
    private $exceptionHandler;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Exec
     */
    private $exec;

    /**
     * Runs Docklyn.
     *
     * @return int 0 if everything went fine, or an error code
     */
    public function run()
    {
        // Prevent symfony from catching exceptions if an exception handler manager has been registered
        if($this->exceptionHandler instanceof ExceptionHandlerManagerInterface){
            $this->application->setCatchExceptions(false);
        }

        return $this->application->run($this->input, $this->output);
    }

    /**
     * @return Application
     */
    public function getApplication()
    {
        return $this->application;
    }

    /**
     * @param Application $application
     */
    public function setApplication(Application $application)
    {
        $this->application = $application;
    }

    /**
     * @return InputInterface
     */
    public function getInput()
    {
        return $this->input;
    }

    /**
     * @param InputInterface $input
     */
    public function setInput(InputInterface $input)
    {
        $this->input = $input;
    }

    /**
     * @return OutputInterface
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * @param OutputInterface $output
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return ExceptionHandlerManagerInterface
     */
    public function getExceptionHandler()
    {
        return $this->exceptionHandler;
    }

    /**
     * @param ExceptionHandlerManagerInterface $exception_handler
     */
    public function setExceptionHandler(ExceptionHandlerManagerInterface $exception_handler)
    {
        $this->exceptionHandler = $exception_handler;
    }

    /**
     * @return Filesystem
     */
    public function getFilesystem()
    {
        return $this->filesystem;
    }

    /**
     * @param Filesystem $filesystem
     */
    public function setFilesystem(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @return Exec
     */
    public function getExec()
    {
        return $this->exec;
    }

    /**
     * @param Exec $exec
     */
    public function setExec(Exec $exec)
    {
        $this->exec = $exec;
    }
}