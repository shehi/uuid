<?php

/**
 * This file is part of the ramsey/uuid library
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright Copyright (c) Ben Ramsey <ben@benramsey.com>
 * @license http://opensource.org/licenses/MIT MIT
 */

declare(strict_types=1);

namespace Ramsey\Uuid;

use Ramsey\Uuid\Builder\DefaultUuidBuilder;
use Ramsey\Uuid\Builder\DegradedUuidBuilder as Rfc4122DegradedUuidBuilder;
use Ramsey\Uuid\Builder\FallbackBuilder;
use Ramsey\Uuid\Builder\UuidBuilderInterface;
use Ramsey\Uuid\Codec\CodecInterface;
use Ramsey\Uuid\Codec\GuidStringCodec;
use Ramsey\Uuid\Codec\StringCodec;
use Ramsey\Uuid\Converter\Number\DegradedNumberConverter;
use Ramsey\Uuid\Converter\Number\GenericNumberConverter;
use Ramsey\Uuid\Converter\Number\GmpConverter;
use Ramsey\Uuid\Converter\NumberConverterInterface;
use Ramsey\Uuid\Converter\Time\DegradedTimeConverter;
use Ramsey\Uuid\Converter\Time\GenericTimeConverter;
use Ramsey\Uuid\Converter\Time\GmpTimeConverter;
use Ramsey\Uuid\Converter\Time\PhpTimeConverter;
use Ramsey\Uuid\Converter\TimeConverterInterface;
use Ramsey\Uuid\Generator\PeclUuidTimeGenerator;
use Ramsey\Uuid\Generator\RandomGeneratorFactory;
use Ramsey\Uuid\Generator\RandomGeneratorInterface;
use Ramsey\Uuid\Generator\TimeGeneratorFactory;
use Ramsey\Uuid\Generator\TimeGeneratorInterface;
use Ramsey\Uuid\Guid\DegradedGuidBuilder;
use Ramsey\Uuid\Guid\GuidBuilder;
use Ramsey\Uuid\Nonstandard\DegradedUuidBuilder as NonstandardDegradedUuidBuilder;
use Ramsey\Uuid\Math\BrickMathCalculator;
use Ramsey\Uuid\Math\CalculatorInterface;
use Ramsey\Uuid\Nonstandard\UuidBuilder as NonstandardUuidBuilder;
use Ramsey\Uuid\Provider\Node\FallbackNodeProvider;
use Ramsey\Uuid\Provider\Node\RandomNodeProvider;
use Ramsey\Uuid\Provider\Node\SystemNodeProvider;
use Ramsey\Uuid\Provider\NodeProviderInterface;
use Ramsey\Uuid\Provider\Time\SystemTimeProvider;
use Ramsey\Uuid\Provider\TimeProviderInterface;
use Ramsey\Uuid\Validator\Validator;
use Ramsey\Uuid\Validator\ValidatorInterface;

/**
 * FeatureSet detects and exposes available features in the current environment
 *
 * A feature set is used by UuidFactory to determine the available features and
 * capabilities of the environment.
 */
class FeatureSet
{
    /**
     * @var bool
     */
    private $disableBigNumber = false;

    /**
     * @var bool
     */
    private $disableGmp = false;

    /**
     * @var bool
     */
    private $disable64Bit = false;

    /**
     * @var bool
     */
    private $ignoreSystemNode = false;

    /**
     * @var bool
     */
    private $enablePecl = false;

    /**
     * @var UuidBuilderInterface
     */
    private $builder;

    /**
     * @var CodecInterface
     */
    private $codec;

    /**
     * @var NodeProviderInterface
     */
    private $nodeProvider;

    /**
     * @var NumberConverterInterface
     */
    private $numberConverter;

    /**
     * @var TimeConverterInterface
     */
    private $timeConverter;

    /**
     * @var RandomGeneratorInterface
     */
    private $randomGenerator;

    /**
     * @var TimeGeneratorInterface
     */
    private $timeGenerator;

    /**
     * @var ValidatorInterface
     */
    private $validator;

    /**
     * @var CalculatorInterface
     */
    private $calculator;

    /**
     * @param bool $useGuids True build UUIDs using the GuidStringCodec
     * @param bool $force32Bit True to force the use of 32-bit functionality
     *     (primarily for testing purposes)
     * @param bool $forceNoBigNumber True to disable the use of moontoast/math
     *     (primarily for testing purposes)
     * @param bool $ignoreSystemNode True to disable attempts to check for the
     *     system node ID (primarily for testing purposes)
     * @param bool $enablePecl True to enable the use of the PeclUuidTimeGenerator
     *     to generate version 1 UUIDs
     * @param bool $forceNoGmp True to disable the use of ext-gmp (primarily
     *     for testing purposes)
     */
    public function __construct(
        bool $useGuids = false,
        bool $force32Bit = false,
        bool $forceNoBigNumber = false,
        bool $ignoreSystemNode = false,
        bool $enablePecl = false,
        bool $forceNoGmp = false
    ) {
        $this->disableBigNumber = $forceNoBigNumber;
        $this->disableGmp = $forceNoGmp;
        $this->disable64Bit = $force32Bit;
        $this->ignoreSystemNode = $ignoreSystemNode;
        $this->enablePecl = $enablePecl;

        $this->calculator = new BrickMathCalculator();
        $this->numberConverter = $this->buildNumberConverter();
        $this->timeConverter = $this->buildTimeConverter();
        $this->builder = $this->buildUuidBuilder($useGuids);
        $this->codec = $this->buildCodec($useGuids);
        $this->nodeProvider = $this->buildNodeProvider();
        $this->randomGenerator = $this->buildRandomGenerator();
        $this->setTimeProvider(new SystemTimeProvider());
        $this->validator = new Validator();
    }

    /**
     * Returns the builder configured for this environment
     */
    public function getBuilder(): UuidBuilderInterface
    {
        return $this->builder;
    }

    /**
     * Returns the calculator configured for this environment
     */
    public function getCalculator(): CalculatorInterface
    {
        return $this->calculator;
    }

    /**
     * Returns the codec configured for this environment
     */
    public function getCodec(): CodecInterface
    {
        return $this->codec;
    }

    /**
     * Returns the node provider configured for this environment
     */
    public function getNodeProvider(): NodeProviderInterface
    {
        return $this->nodeProvider;
    }

    /**
     * Returns the number converter configured for this environment
     */
    public function getNumberConverter(): NumberConverterInterface
    {
        return $this->numberConverter;
    }

    /**
     * Returns the random generator configured for this environment
     */
    public function getRandomGenerator(): RandomGeneratorInterface
    {
        return $this->randomGenerator;
    }

    /**
     * Returns the time generator configured for this environment
     */
    public function getTimeGenerator(): TimeGeneratorInterface
    {
        return $this->timeGenerator;
    }

    /**
     * Returns the validator configured for this environment
     */
    public function getValidator(): ValidatorInterface
    {
        return $this->validator;
    }

    /**
     * Sets the calculator to use in this environment
     */
    public function setCalculator(CalculatorInterface $calculator): void
    {
        $this->calculator = $calculator;
    }

    /**
     * Sets the time provider to use in this environment
     */
    public function setTimeProvider(TimeProviderInterface $timeProvider): void
    {
        $this->timeGenerator = $this->buildTimeGenerator($timeProvider);
    }

    /**
     * Set the validator to use in this environment
     */
    public function setValidator(ValidatorInterface $validator): void
    {
        $this->validator = $validator;
    }

    /**
     * Returns a codec configured for this environment
     *
     * @param bool $useGuids Whether to build UUIDs using the GuidStringCodec
     */
    private function buildCodec(bool $useGuids = false): CodecInterface
    {
        if ($useGuids) {
            return new GuidStringCodec($this->builder);
        }

        return new StringCodec($this->builder);
    }

    /**
     * Returns a node provider configured for this environment
     */
    private function buildNodeProvider(): NodeProviderInterface
    {
        if ($this->ignoreSystemNode) {
            return new RandomNodeProvider();
        }

        return new FallbackNodeProvider([
            new SystemNodeProvider(),
            new RandomNodeProvider(),
        ]);
    }

    /**
     * Returns a number converter configured for this environment
     */
    private function buildNumberConverter(): NumberConverterInterface
    {
        return new GenericNumberConverter($this->getCalculator());
    }

    /**
     * Returns a random generator configured for this environment
     */
    private function buildRandomGenerator(): RandomGeneratorInterface
    {
        return (new RandomGeneratorFactory())->getGenerator();
    }

    /**
     * Returns a time generator configured for this environment
     *
     * @param TimeProviderInterface $timeProvider The time provider to use with
     *     the time generator
     */
    private function buildTimeGenerator(TimeProviderInterface $timeProvider): TimeGeneratorInterface
    {
        if ($this->enablePecl) {
            return new PeclUuidTimeGenerator();
        }

        return (new TimeGeneratorFactory(
            $this->nodeProvider,
            $this->timeConverter,
            $timeProvider
        ))->getGenerator();
    }

    /**
     * Returns a time converter configured for this environment
     */
    private function buildTimeConverter(): TimeConverterInterface
    {
        $genericConverter = new GenericTimeConverter($this->getCalculator());

        if ($this->is64BitSystem()) {
            return new PhpTimeConverter($genericConverter);
        }

        return $genericConverter;
    }

    /**
     * Returns a UUID builder configured for this environment
     *
     * @param bool $useGuids Whether to build UUIDs using the GuidStringCodec
     */
    private function buildUuidBuilder(bool $useGuids = false): UuidBuilderInterface
    {
        if ($this->is64BitSystem() && $useGuids) {
            return new GuidBuilder($this->numberConverter, $this->timeConverter);
        }

        if ($this->is64BitSystem()) {
            return new FallbackBuilder([
                new DefaultUuidBuilder($this->numberConverter, $this->timeConverter),
                new NonstandardUuidBuilder($this->numberConverter, $this->timeConverter),
            ]);
        }

        if ($useGuids) {
            return new DegradedGuidBuilder($this->numberConverter, $this->timeConverter);
        }

        return new FallbackBuilder([
            new Rfc4122DegradedUuidBuilder($this->numberConverter, $this->timeConverter),
            new NonstandardDegradedUuidBuilder($this->numberConverter, $this->timeConverter),
        ]);
    }

    /**
     * Returns true if moontoast/math is available
     */
    private function hasBigNumber(): bool
    {
        return class_exists('Moontoast\Math\BigNumber') && !$this->disableBigNumber;
    }

    /**
     * Returns true if ext-gmp is available
     */
    private function hasGmp(): bool
    {
        return extension_loaded('gmp') && !$this->disableGmp;
    }

    /**
     * Returns true if the PHP build is 64-bit
     */
    private function is64BitSystem(): bool
    {
        return PHP_INT_SIZE === 8 && !$this->disable64Bit;
    }
}
