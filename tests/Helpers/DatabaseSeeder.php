<?php

namespace LaravelMint\Tests\Helpers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder
{
    /**
     * Set up test database with sample schema
     */
    public static function setupTestDatabase(): void
    {
        // Create users table
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('remember_token')->nullable();
            $table->timestamps();
        });

        // Create posts table
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('content');
            $table->string('status')->default('draft');
            $table->integer('views')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // Create comments table
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('content');
            $table->boolean('is_approved')->default(false);
            $table->timestamps();
        });

        // Create categories table
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Create post_categories pivot table
        Schema::create('post_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->unique(['post_id', 'category_id']);
        });

        // Create products table (for e-commerce testing)
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->integer('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('attributes')->nullable();
            $table->timestamps();
        });

        // Create orders table
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('order_number')->unique();
            $table->decimal('total', 10, 2);
            $table->string('status')->default('pending');
            $table->json('shipping_address')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamps();
        });

        // Create order_items table
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->integer('quantity');
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Seed test database with sample data
     */
    public static function seedTestData(): array
    {
        $data = [];

        // Create users
        $users = [];
        for ($i = 1; $i <= 10; $i++) {
            $users[] = DB::table('users')->insertGetId([
                'name' => "User {$i}",
                'email' => "user{$i}@example.com",
                'password' => bcrypt('password'),
                'created_at' => now()->subDays(rand(1, 365)),
                'updated_at' => now(),
            ]);
        }
        $data['users'] = $users;

        // Create categories
        $categories = [];
        $categoryNames = ['Technology', 'Science', 'Business', 'Health', 'Entertainment'];
        foreach ($categoryNames as $name) {
            $categories[] = DB::table('categories')->insertGetId([
                'name' => $name,
                'slug' => strtolower($name),
                'description' => "Category for {$name} related content",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $data['categories'] = $categories;

        // Create posts
        $posts = [];
        for ($i = 1; $i <= 30; $i++) {
            $posts[] = DB::table('posts')->insertGetId([
                'user_id' => $users[array_rand($users)],
                'title' => "Post Title {$i}",
                'content' => "This is the content for post {$i}. Lorem ipsum dolor sit amet.",
                'status' => ['draft', 'published', 'archived'][rand(0, 2)],
                'views' => rand(0, 1000),
                'published_at' => rand(0, 1) ? now()->subDays(rand(1, 30)) : null,
                'created_at' => now()->subDays(rand(1, 60)),
                'updated_at' => now(),
            ]);
        }
        $data['posts'] = $posts;

        // Create post-category relationships
        foreach ($posts as $postId) {
            $numCategories = rand(1, 3);
            $assignedCategories = array_rand(array_flip($categories), $numCategories);
            if (! is_array($assignedCategories)) {
                $assignedCategories = [$assignedCategories];
            }

            foreach ($assignedCategories as $categoryId) {
                DB::table('post_categories')->insert([
                    'post_id' => $postId,
                    'category_id' => $categoryId,
                ]);
            }
        }

        // Create comments
        $comments = [];
        foreach ($posts as $postId) {
            $numComments = rand(0, 5);
            for ($j = 0; $j < $numComments; $j++) {
                $comments[] = DB::table('comments')->insertGetId([
                    'post_id' => $postId,
                    'user_id' => $users[array_rand($users)],
                    'content' => "This is comment {$j} for post {$postId}",
                    'is_approved' => rand(0, 1) === 1,
                    'created_at' => now()->subDays(rand(1, 30)),
                    'updated_at' => now(),
                ]);
            }
        }
        $data['comments'] = $comments;

        // Create products
        $products = [];
        for ($i = 1; $i <= 20; $i++) {
            $products[] = DB::table('products')->insertGetId([
                'name' => "Product {$i}",
                'sku' => 'SKU-'.str_pad($i, 5, '0', STR_PAD_LEFT),
                'description' => "Description for product {$i}",
                'price' => rand(1000, 50000) / 100,
                'stock' => rand(0, 100),
                'is_active' => rand(0, 9) > 1, // 90% active
                'attributes' => json_encode([
                    'color' => ['red', 'blue', 'green'][rand(0, 2)],
                    'size' => ['S', 'M', 'L', 'XL'][rand(0, 3)],
                ]),
                'created_at' => now()->subDays(rand(1, 180)),
                'updated_at' => now(),
            ]);
        }
        $data['products'] = $products;

        // Create orders
        $orders = [];
        for ($i = 1; $i <= 15; $i++) {
            $orderId = DB::table('orders')->insertGetId([
                'user_id' => $users[array_rand($users)],
                'order_number' => 'ORD-'.str_pad($i, 6, '0', STR_PAD_LEFT),
                'total' => 0, // Will update after adding items
                'status' => ['pending', 'processing', 'shipped', 'delivered'][rand(0, 3)],
                'shipping_address' => json_encode([
                    'street' => "{$i} Main St",
                    'city' => 'City',
                    'state' => 'State',
                    'zip' => '12345',
                ]),
                'shipped_at' => rand(0, 1) ? now()->subDays(rand(1, 10)) : null,
                'created_at' => now()->subDays(rand(1, 30)),
                'updated_at' => now(),
            ]);

            // Add order items
            $orderTotal = 0;
            $numItems = rand(1, 5);
            $selectedProducts = array_rand(array_flip($products), $numItems);
            if (! is_array($selectedProducts)) {
                $selectedProducts = [$selectedProducts];
            }

            foreach ($selectedProducts as $productId) {
                $product = DB::table('products')->find($productId);
                $quantity = rand(1, 3);
                $itemPrice = $product->price;
                $orderTotal += $itemPrice * $quantity;

                DB::table('order_items')->insert([
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'price' => $itemPrice,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Update order total
            DB::table('orders')->where('id', $orderId)->update(['total' => $orderTotal]);
            $orders[] = $orderId;
        }
        $data['orders'] = $orders;

        return $data;
    }

    /**
     * Clean up test database
     */
    public static function cleanupTestDatabase(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');
        Schema::dropIfExists('post_categories');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('users');
    }
}
