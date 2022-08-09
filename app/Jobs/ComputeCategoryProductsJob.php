<?php

namespace App\Jobs;

use App\Models\ProductCategory;
use App\Services\ProductCategoryService;
use App\Traits\NsSerialize;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ComputeCategoryProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, NsSerialize;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        public ProductCategory $productCategory
    )
    {
        $this->prepareSerialization();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(
        ProductCategoryService $productCategoryService
    )
    {
        $productCategoryService->computeProducts( $this->productCategory );
    }
}