# Contributing to Laravel Mint

Thanks for considering contributing to Laravel Mint! We really appreciate any help making this package better for the Laravel community.

## How Can I Contribute?

### Reporting Bugs

Found a bug? Before creating an issue, please check if it's already been reported. If not, create a new issue with:

- A clear title and description
- Steps to reproduce the issue
- Expected behavior vs actual behavior
- Your environment details (PHP version, Laravel version, etc.)
- Any relevant code snippets or error messages

### Suggesting Features

Got an idea for a new feature? We'd love to hear it! Open an issue with:

- A clear description of the feature
- Why it would be useful
- Example use cases
- Any implementation ideas you might have

### Pull Requests

Ready to contribute code? Awesome! Here's the process:

1. **Fork the repository** and create your branch from `main`
2. **Write tests** for your changes (we aim for high test coverage)
3. **Write your code** following our coding standards
4. **Run the tests** to make sure everything passes
5. **Format your code** using Laravel Pint
6. **Update documentation** if needed
7. **Submit your PR** with a clear description of your changes

## Development Setup

```bash
# Clone your fork
git clone https://github.com/your-username/laravel-mint.git
cd laravel-mint

# Install dependencies
composer install

# Set up testing environment
cp phpunit.xml.dist phpunit.xml

# Run tests
composer test

# Format code
composer format
```

## Coding Standards

We follow PSR-12 coding standards and use Laravel Pint for automatic formatting:

```bash
# Format your code before committing
composer format

# Or manually
./vendor/bin/pint
```

### Code Style Guidelines

- Use meaningful variable and method names
- Add comments for complex logic
- Keep methods focused and small (preferably under 20 lines)
- Use type hints and return types where possible
- Follow Laravel naming conventions

### Example of Good Code

```php
/**
 * Generate data with the specified pattern
 */
public function generateWithPattern(string $modelClass, int $count, PatternInterface $pattern): Collection
{
    // Validate inputs
    if (!class_exists($modelClass)) {
        throw new InvalidArgumentException("Model class {$modelClass} does not exist");
    }
    
    // Generate data
    $results = collect();
    
    for ($i = 0; $i < $count; $i++) {
        $data = $pattern->generate();
        $results->push($modelClass::create($data));
    }
    
    return $results;
}
```

## Testing

We take testing seriously. Please write tests for your changes:

### Running Tests

```bash
# Run all tests
composer test

# Run specific test
./vendor/bin/phpunit tests/Unit/YourTest.php

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage
```

### Writing Tests

```php
namespace LaravelMint\Tests\Unit;

use LaravelMint\Tests\TestCase;
use LaravelMint\YourClass;

class YourClassTest extends TestCase
{
    public function test_it_does_something()
    {
        // Arrange
        $class = new YourClass();
        
        // Act
        $result = $class->doSomething();
        
        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

## Documentation

If your changes affect how users interact with the package:

1. Update the README.md with new examples or features
2. Add inline documentation (PHPDoc) to your code
3. Update CHANGELOG.md if it's a notable change

### Documentation Style

- Write clear, concise explanations
- Include code examples where helpful
- Explain the "why" not just the "how"
- Keep language friendly and approachable

## Commit Messages

We follow conventional commit messages:

- `feat:` New feature
- `fix:` Bug fix
- `docs:` Documentation changes
- `test:` Test additions or changes
- `refactor:` Code refactoring
- `style:` Code style changes
- `perf:` Performance improvements
- `chore:` Maintenance tasks

Examples:
```
feat: add Poisson distribution pattern
fix: correct memory leak in stream processor
docs: add example for custom scenarios
test: add tests for import manager
```

## Review Process

After submitting your PR:

1. **Automated checks** will run (tests, code style)
2. **A maintainer will review** your code
3. **Address any feedback** from the review
4. **Once approved**, your PR will be merged

We aim to review PRs within 48 hours, though complex changes might take longer.

## Community Guidelines

- Be respectful and inclusive
- Welcome newcomers and help them get started
- Focus on constructive criticism
- Appreciate all contributions, big or small

## Getting Help

Need help with your contribution?

- Check existing issues and PRs for similar work
- Ask questions in the issue or PR
- Reach out via [GitHub Discussions](https://github.com/argent/laravel-mint/discussions)

## Recognition

Contributors are recognized in our README and release notes. We appreciate every contribution that helps make Laravel Mint better!

## License

By contributing to Laravel Mint, you agree that your contributions will be licensed under the MIT License.