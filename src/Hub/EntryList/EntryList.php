<?php

namespace Hub\EntryList;

use Symfony\Component\Config as SymfonyConfig;
use Hub\IO\IOInterface;
use Hub\Entry\EntryInterface;
use Hub\EntryList\Source\SourceInterface;
use Hub\EntryList\SourceProcessor\SourceProcessorInterface;
use Hub\Entry\Resolver\EntryResolverInterface;
use Hub\Exceptions\EntryResolveFailedException;
use Hub\Util\NestedArray;

/**
 * The Base List class.
 */
class EntryList implements EntryListInterface
{
    /**
     * @var array
     */
    protected $data;

    /**
     * @var EntryInterface[]
     */
    protected $entries = [];

    /**
     * @var array
     */
    protected $categories = [];

    /**
     * @var int
     */
    protected $categoryLastInsert = 0;

    /**
     * @var bool
     */
    protected $processed = false;

    /**
     * @var bool
     */
    protected $resolved = false;

    /**
     * Constructor.
     *
     * @param array $data List definition
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function __construct(array $data)
    {
        try {
            $this->data = $this->verify($data);
        } catch (SymfonyConfig\Definition\Exception\Exception $e) {
            throw new \InvalidArgumentException("Unable to process the list definition data; {$e->getMessage()}.", 0, $e);
        }

        foreach ($this->data['sources'] as $i => $source) {
            $options = isset($this->data['options']['source'])
                // Merge top-level source options
                ? NestedArray::merge($this->data['options']['source'], $source['options'])
                : $source['options'];
            $this->data['sources'][$i] = new Source\Source($source['type'], $source['data'], $options);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return strtolower($this->get('id'));
    }

    /**
     * {@inheritdoc}
     */
    public function getCategories()
    {
        return $this->categories;
    }

    /**
     * {@inheritdoc}
     */
    public function getEntries()
    {
        return $this->entries;
    }

    /**
     * {@inheritdoc}
     */
    public function isProcessed()
    {
        return $this->processed;
    }

    /**
     * {@inheritdoc}
     */
    public function isResolved()
    {
        return $this->resolved;
    }

    /**
     * {@inheritdoc}
     */
    public function has($key)
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * {@inheritdoc}
     */
    public function get($key = null)
    {
        if (null === $key) {
            return $this->data;
        }

        if (!array_key_exists($key, $this->data)) {
            throw new \InvalidArgumentException(sprintf("Trying to get an undefined list data key '%s'", $key));
        }

        return $this->data[$key];
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value = null)
    {
        if (func_num_args() === 1) {
            if (!is_array($key)) {
                throw new \UnexpectedValueException(sprintf('Expected array but got %s', var_export($key, true)));
            }

            $this->data = $key;

            return;
        }

        $this->data[$key] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function process(IOInterface $io, array $processors)
    {
        if (empty($processors)) {
            throw new \LogicException('Cannot process the list; No source processors has been provided.');
        }
        $logger = $io->getLogger();

        $logger->info('Processing list sources');
        $this->processSources($io, $processors);
        $logger->info(sprintf('Processed %d entry(s)', count($this->entries)));

        $logger->info('Organizing categories');
        foreach ($this->entries as $entry) {
            $categories = [];
            foreach ($entry->get('categories') as $category) {
                // Strip non-utf chars
                $category = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $category);
                $category = trim($category);
                if (!empty($category) && !in_array($category, $categories)) {
                    $categories[] = $category;
                }
            }

            $categoryIds = [];
            foreach ($categories as $category) {
                $categoryIds = array_merge(
                    $categoryIds,
                    $this->insertCategory($category, [
                        'all'             => 1,
                        $entry->getType() => 1,
                    ])
                );
            }

            $entry->set('categories', $categoryIds);
        }

        // Add category order
        $categoryOrder = $this->data['options']['categoryOrder'];
        foreach ($this->categories as $id => $category) {
            $order = 20;
            if(array_key_exists($category['path'], $categoryOrder)){
                $order = (int) $categoryOrder[$category['path']];
            }
            $this->categories[$id]['order'] = $order;
        }

        $this->processed = true;
        $logger->info(sprintf('Organized %d category(s)', count($this->categories)));
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(IOInterface $io, array $resolvers, $force = false)
    {
        // Santity check
        if (!$this->isProcessed()) {
            throw new \LogicException('Can not resolve the listt while it is not processed');
        }

        if (empty($resolvers)) {
            throw new \LogicException('Cannot resolve the list; No resolvers has been provided');
        }

        if (empty($this->entries)) {
            throw new \LogicException('No entries to resolve');
        }

        $logger = $io->getLogger();

        $logger->info('Resolving list entries');
        $io->startOverwrite();
        $indicator = ' [ %%spinner%% ] Resolving entry#%d => %s (%%elapsed%%)';

        $i = $ir = $ic = 0;
        foreach ($this->entries as $id => $entry) {
            ++$i;
            $resolvedWith = false;
            $isCached     = false;
            /* @var EntryResolverInterface $resolver */
            foreach ($resolvers as $resolver) {
                if ($resolver->supports($entry)) {
                    $resolvedWith = $resolver;
                    $isCached     = $resolver->isCached($entry);
                    $io->write(sprintf($indicator, $i, $id));
                    try {
                        $resolver->resolve($entry, $force);
                    } catch (EntryResolveFailedException $e) {
                        $this->removeEntry($entry);
                        $logger->warning(sprintf(
                            "Failed resolving entry#%d [%s] with '%s'; %s",
                            $i, $id, get_class($resolver), $e->getMessage()
                        ));
                        continue 2;
                    }
                    break;
                }
            }

            // Check if no resolver can resolve this entry
            if (false === $resolvedWith) {
                $this->removeEntry($entry);
                $logger->warning(sprintf(
                    "Ignoring entry#%d [%s] of type '%s'; None of the given resolvers supports it",
                    $i, $id, get_class($entry)
                ));
                continue;
            }

            if ($isCached) {
                ++$ic;
            }

            ++$ir;
        }

        $this->resolved = true;
        $logger->info(sprintf('Resolved %d/%d entry(s) with %d cached entry(s)',
            $ir, $i, $ic
        ));
        $io->endOverwrite();
    }

    /**
     * {@inheritdoc}
     */
    public function removeEntry(EntryInterface $entry)
    {
        // Santity check
        if (!$this->isProcessed()) {
            throw new \LogicException('Can not remove an entry while the list is not processed');
        }

        // Remove from entries
        unset($this->entries[$entry->getId()]);

        // Update cat counts
        foreach ($this->categories as $id => $category) {
            if (in_array($id, $entry->get('categories'))) {
                --$this->categories[$id]['count']['all'];
                --$this->categories[$id]['count'][$entry->getType()];

                // Remove the category if it hs no entries
                if (1 > $this->categories[$id]['count']['all']) {
                    unset($this->categories[$id]);
                }
            }
        }
    }

    /**
     * Adds an entry to the list.
     *
     * @param EntryInterface  $entry
     * @param SourceInterface $source
     */
    protected function addEntry(EntryInterface $entry, SourceInterface $source)
    {
        $id = $entry->getId();
        if ($source->hasOption('exclude')) {
            foreach ($source->getOption('exclude', []) as $regex) {
                $regex = $regex[0] !== '/' ? "/${regex}/" : $regex;
                if (false === @preg_match($regex, null)) {
                    throw new \InvalidArgumentException(sprintf("Invalid exclude regex '%s'", $regex));
                }

                if (preg_match($regex, $id)) {
                    return;
                }
            }
        }

        // Check if an already existing entry exists
        if (isset($this->entries[$id])) {
            $this->entries[$id]->merge($entry->get());
            $entry = $this->entries[$id];
        }

        // Check if a single category is defined
        if ($source->hasOption('category')) {
            $entry->set('categories', [
                $source->getOption('category', null),
            ]);
            // Save the entry
            $this->entries[$id] = $entry;

            return;
        }

        $categories = [];
        if ($entry->has('categories')) {
            $categories = $entry->get('categories');
        }

        // Add source categories
        if ($source->hasOption('categories')) {
            foreach ($source->getOption('categories', []) as $category => $regexs) {
                if (!is_array($regexs)) {
                    $regexs = [$regexs];
                }

                foreach ($regexs as $regex) {
                    $regex = $regex === '*' ? '.*' : $regex;
                    $regex = $regex[0] !== '/' ? "/${regex}/" : $regex;
                    if (false === @preg_match($regex, null)) {
                        throw new \InvalidArgumentException(sprintf("Invalid category regex '%s'", $regex));
                    }

                    if (preg_match($regex, $id)) {
                        if (!is_array($category)) {
                            $category = [$category];
                        }
                        $categories = array_merge($categories, $category);
                    }
                }
            }
        }

        // Set the final categories
        $entry->set('categories', $categories);
        // Save the entry
        $this->entries[$id] = $entry;
    }

    /**
     * Recursively processes list sources.
     *
     * @param IOInterface                $io
     * @param SourceProcessorInterface[] $processors
     * @param SourceInterface[]|null     $sources
     * @param int                        $depth
     */
    protected function processSources(IOInterface $io, array $processors, array $sources = [], $depth = 0)
    {
        $root      = 0 === $depth;
        $logger    = $io->getLogger();
        $depthStr  = $root ? '' : str_repeat('|_ ', $depth);
        $indicator = ' [ %%spinner%% ] %s (%%elapsed%%)';

        if ($root) {
            $io->startOverwrite();
            $sources = $this->data['sources'];
        }

        foreach ($sources as $index => $source) {
            $id            = ($root ? 'index='.$index.' ' : '').'type='.$source->getType();
            $processedWith = false;
            $callback      = function ($event, $payload) use ($source, $io, $indicator) {
                switch ($event) {
                    case SourceProcessorInterface::ON_STATUS_UPDATE:
                        if ($payload['type'] === 'error') {
                            $io->getLogger()->warning($payload['message']);
                            break;
                        }
                        $io->write(sprintf($indicator, $payload['message']));
                        break;

                    case SourceProcessorInterface::ON_ENTRY_CREATED:
                        $this->addEntry($payload, $source);
                        break;

                    default:
                        throw new \UnexpectedValueException(
                            sprintf("Unsupported source processor event '%s'", $event)
                        );
                }
            };

            foreach ($processors as $processor) {
                $processorName = basename(str_replace('\\', '/', get_class($processor)));
                switch ($processor->getAction($source)) {
                    case SourceProcessorInterface::ACTION_PARTIAL_PROCESSING:
                        $processedWith = $processor;
                        $logger->info(sprintf("%sProcessing source[%s] with '%s'", $depthStr, $id, $processorName));
                        try {
                            $childSources = $processor->process($source, $callback);
                        } catch (\Exception $e) {
                            $logger->critical(sprintf("Failed processing source[%s] with '%s'; %s",
                                $id, $processorName, $e->getMessage()
                            ));
                            continue;
                        }

                        if (!is_array($childSources)) {
                            $childSources = [$childSources];
                        }

                        if (count($childSources) === 0) {
                            $logger->warning(sprintf(
                                "No child sources from processing source[%s] with '%s'",
                                $id, $processorName
                            ));
                            continue;
                        }

                        $this->processSources($io, $processors, $childSources, $depth + 1);
                        break;

                    case SourceProcessorInterface::ACTION_PROCESSING:
                        $processedWith = $processor;
                        $logger->info(sprintf("%sProcessing source[%s] with '%s'", $depthStr, $id, $processorName));
                        try {
                            $processor->process($source, $callback);
                        } catch (\Exception $e) {
                            $logger->critical(sprintf("Failed processing source[%s] with '%s'; %s",
                                $id, $processorName, $e->getMessage()
                            ));
                        }
                        break;

                    case SourceProcessorInterface::ACTION_SKIP:
                        break;

                    default:
                        throw new \UnexpectedValueException(sprintf(
                            "Got an invalid processing mode from processor '%s'", get_class($processor)
                        ));
                }
            }

            // Check if no processor can process this source
            if (false === $processedWith) {
                $logger->critical(sprintf('Ignoring source[%s]; None of the given processors supports it.', $id));
                continue;
            }

            $logger->info(sprintf('%sFinished processing source[%s]', $depthStr, $id));
        }

        if ($root) {
            $io->endOverwrite();
        }
    }

    /**
     * Verifies list definition array and returns the processed array.
     *
     * @param array $data List data
     *
     * @return array Processed list definition array
     */
    protected function verify($data)
    {
        return (new SymfonyConfig\Definition\Processor())->processConfiguration(
            new EntryListDefinition(),
            [$data]
        );
    }

    /**
     * Adds a new category.
     *  Takes a category path like 'Category/Sub Category/Demo' and returns their
     *  ids while adding them if not already added.
     *
     * @param string $category
     * @param array  $count
     *
     * @return array|bool
     */
    protected function insertCategory($category, array $count = [])
    {
        $path   = [];
        $return = [];
        foreach (explode('/', trim($category, '/')) as $title) {
            $parentPath = implode('/', $path);
            $title      = ucfirst(trim($title));
            $path[]     = $this->slugify($title);
            $pathString = implode('/', $path);
            $paths      = array_column($this->categories, 'path', 'id');
            if (in_array($pathString, $paths)) {
                $return[] = $id = array_search($pathString, $paths);
                $object   = &$this->categories[$id];
                // Allow overwriting the category title
                $object['title'] = $title;
                foreach ($count as $ckey => $cval) {
                    if (!isset($object['count'][$ckey])) {
                        $object['count'][$ckey] = $cval;
                        continue;
                    }
                    $object['count'][$ckey] += $cval;
                }
                continue;
            }

            $return[] = $id = ++$this->categoryLastInsert;

            $this->categories[$id] = [
                'id'     => $id,
                'title'  => $title,
                'path'   => $pathString,
                'parent' => (int) array_search($parentPath, $paths),
                'count'  => $count,
            ];
        }

        return $return;
    }

    /**
     * @param string $str
     *
     * @return mixed|string
     */
    protected function slugify($str)
    {
        $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
        $str = preg_replace('/[^\w\d\-]/', ' ', $str);
        $str = str_replace(' ', '-', strtolower(trim($str, '-')));

        return preg_replace('/\-{2,}/', '-', $str);
    }
}
