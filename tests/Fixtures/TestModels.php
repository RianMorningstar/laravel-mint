<?php

namespace LaravelMint\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Test User Model
 */
class TestUser extends Model
{
    protected $table = 'users';
    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
    
    public function posts(): HasMany
    {
        return $this->hasMany(TestPost::class, 'user_id');
    }
    
    public function comments(): HasMany
    {
        return $this->hasMany(TestComment::class, 'user_id');
    }
    
    public function orders(): HasMany
    {
        return $this->hasMany(TestOrder::class, 'user_id');
    }
}

/**
 * Test Post Model
 */
class TestPost extends Model
{
    protected $table = 'posts';
    protected $fillable = ['user_id', 'title', 'content', 'status', 'views', 'published_at'];
    protected $casts = [
        'published_at' => 'datetime',
        'views' => 'integer',
    ];
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'user_id');
    }
    
    public function comments(): HasMany
    {
        return $this->hasMany(TestComment::class, 'post_id');
    }
    
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(TestCategory::class, 'post_categories', 'post_id', 'category_id');
    }
    
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }
    
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}

/**
 * Test Comment Model
 */
class TestComment extends Model
{
    protected $table = 'comments';
    protected $fillable = ['post_id', 'user_id', 'content', 'is_approved'];
    protected $casts = [
        'is_approved' => 'boolean',
    ];
    
    public function post(): BelongsTo
    {
        return $this->belongsTo(TestPost::class, 'post_id');
    }
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'user_id');
    }
    
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }
}

/**
 * Test Category Model
 */
class TestCategory extends Model
{
    protected $table = 'categories';
    protected $fillable = ['name', 'slug', 'description'];
    
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(TestPost::class, 'post_categories', 'category_id', 'post_id');
    }
}

/**
 * Test Product Model
 */
class TestProduct extends Model
{
    protected $table = 'products';
    protected $fillable = ['name', 'sku', 'description', 'price', 'stock', 'is_active', 'attributes'];
    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'is_active' => 'boolean',
        'attributes' => 'array',
    ];
    
    public function orderItems(): HasMany
    {
        return $this->hasMany(TestOrderItem::class, 'product_id');
    }
    
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }
}

/**
 * Test Order Model
 */
class TestOrder extends Model
{
    protected $table = 'orders';
    protected $fillable = ['user_id', 'order_number', 'total', 'status', 'shipping_address', 'shipped_at'];
    protected $casts = [
        'total' => 'decimal:2',
        'shipping_address' => 'array',
        'shipped_at' => 'datetime',
    ];
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'user_id');
    }
    
    public function items(): HasMany
    {
        return $this->hasMany(TestOrderItem::class, 'order_id');
    }
    
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
    
    public function scopeCompleted($query)
    {
        return $query->whereIn('status', ['shipped', 'delivered']);
    }
}

/**
 * Test OrderItem Model
 */
class TestOrderItem extends Model
{
    protected $table = 'order_items';
    protected $fillable = ['order_id', 'product_id', 'quantity', 'price'];
    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
    ];
    
    public function order(): BelongsTo
    {
        return $this->belongsTo(TestOrder::class, 'order_id');
    }
    
    public function product(): BelongsTo
    {
        return $this->belongsTo(TestProduct::class, 'product_id');
    }
    
    public function getSubtotalAttribute()
    {
        return $this->quantity * $this->price;
    }
}