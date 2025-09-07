<?php

namespace LaravelMint\Tests\Helpers;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;

trait AssertionHelpers
{
    /**
     * Assert that generated data follows a specific pattern
     */
    protected function assertDataFollowsPattern(array $data, string $pattern, string $message = ''): void
    {
        foreach ($data as $value) {
            Assert::assertMatchesRegularExpression(
                $pattern,
                (string) $value,
                $message ?: "Value '{$value}' does not match pattern '{$pattern}'"
            );
        }
    }

    /**
     * Assert that data distribution is within expected range
     */
    protected function assertDataDistribution(array $data, float $expectedMean, float $tolerance = 0.1): void
    {
        $mean = array_sum($data) / count($data);
        $lowerBound = $expectedMean * (1 - $tolerance);
        $upperBound = $expectedMean * (1 + $tolerance);

        Assert::assertGreaterThanOrEqual(
            $lowerBound,
            $mean,
            "Mean {$mean} is below expected range [{$lowerBound}, {$upperBound}]"
        );

        Assert::assertLessThanOrEqual(
            $upperBound,
            $mean,
            "Mean {$mean} is above expected range [{$lowerBound}, {$upperBound}]"
        );
    }

    /**
     * Assert that a collection has specific structure
     */
    protected function assertCollectionStructure(Collection $collection, array $expectedKeys): void
    {
        $collection->each(function ($item) use ($expectedKeys) {
            foreach ($expectedKeys as $key) {
                Assert::assertArrayHasKey(
                    $key,
                    $item instanceof \ArrayAccess ? $item->toArray() : (array) $item,
                    "Collection item missing expected key: {$key}"
                );
            }
        });
    }

    /**
     * Assert that data is unique within tolerance
     */
    protected function assertDataUniqueness(array $data, float $minUniquenessRatio = 0.8): void
    {
        $uniqueCount = count(array_unique($data));
        $totalCount = count($data);
        $uniquenessRatio = $uniqueCount / $totalCount;

        Assert::assertGreaterThanOrEqual(
            $minUniquenessRatio,
            $uniquenessRatio,
            "Data uniqueness ratio {$uniquenessRatio} is below minimum {$minUniquenessRatio}"
        );
    }

    /**
     * Assert that relationships are properly loaded
     */
    protected function assertRelationshipsLoaded($model, array $relationships): void
    {
        foreach ($relationships as $relation) {
            Assert::assertTrue(
                $model->relationLoaded($relation),
                "Relationship '{$relation}' is not loaded on model"
            );
        }
    }

    /**
     * Assert that generated data respects constraints
     */
    protected function assertDataConstraints(array $data, array $constraints): void
    {
        foreach ($data as $item) {
            foreach ($constraints as $field => $constraint) {
                $value = data_get($item, $field);

                if (isset($constraint['min'])) {
                    Assert::assertGreaterThanOrEqual(
                        $constraint['min'],
                        $value,
                        "Field '{$field}' value {$value} is below minimum {$constraint['min']}"
                    );
                }

                if (isset($constraint['max'])) {
                    Assert::assertLessThanOrEqual(
                        $constraint['max'],
                        $value,
                        "Field '{$field}' value {$value} is above maximum {$constraint['max']}"
                    );
                }

                if (isset($constraint['in'])) {
                    Assert::assertContains(
                        $value,
                        $constraint['in'],
                        "Field '{$field}' value {$value} is not in allowed values"
                    );
                }

                if (isset($constraint['pattern'])) {
                    Assert::assertMatchesRegularExpression(
                        $constraint['pattern'],
                        (string) $value,
                        "Field '{$field}' value {$value} does not match pattern"
                    );
                }
            }
        }
    }

    /**
     * Assert performance metrics
     */
    protected function assertPerformance(callable $operation, float $maxSeconds = 1.0, int $maxMemoryMb = 50): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $operation();

        $elapsedTime = microtime(true) - $startTime;
        $memoryUsed = (memory_get_usage(true) - $startMemory) / 1024 / 1024;

        Assert::assertLessThanOrEqual(
            $maxSeconds,
            $elapsedTime,
            "Operation took {$elapsedTime} seconds, exceeding limit of {$maxSeconds} seconds"
        );

        Assert::assertLessThanOrEqual(
            $maxMemoryMb,
            $memoryUsed,
            "Operation used {$memoryUsed} MB, exceeding limit of {$maxMemoryMb} MB"
        );
    }

    /**
     * Assert that cache was used
     */
    protected function assertCacheHit(string $key, callable $operation): void
    {
        // Clear cache first
        cache()->forget($key);

        // First call should miss cache
        $firstResult = $operation();

        // Second call should hit cache
        $secondResult = $operation();

        Assert::assertEquals(
            $firstResult,
            $secondResult,
            'Cache results do not match'
        );
    }

    /**
     * Assert array structure recursively
     */
    protected function assertArrayStructure(array $array, array $structure): void
    {
        foreach ($structure as $key => $value) {
            if (is_array($value) && $key === '*') {
                Assert::assertIsArray($array);

                foreach ($array as $item) {
                    $this->assertArrayStructure($item, $value);
                }
            } elseif (is_array($value)) {
                Assert::assertArrayHasKey($key, $array);
                $this->assertArrayStructure($array[$key], $value);
            } else {
                Assert::assertArrayHasKey($value, $array);
            }
        }
    }

    /**
     * Assert that an exception is thrown with specific message
     */
    protected function assertExceptionMessage(callable $operation, string $expectedMessage): void
    {
        try {
            $operation();
            Assert::fail("Expected exception with message '{$expectedMessage}' was not thrown");
        } catch (\Exception $e) {
            Assert::assertStringContainsString(
                $expectedMessage,
                $e->getMessage(),
                'Exception message does not contain expected text'
            );
        }
    }
}
