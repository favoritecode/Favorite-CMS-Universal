<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Support\ProductPricingCalculator;
use InvalidArgumentException;
use RuntimeException;

class ProductManagementService
{
    protected ProductRepository $repository;
    protected DigitalFileStorageService $storageService;

    public function __construct(
        ProductRepository $repository,
        DigitalFileStorageService $storageService
    ) {
        $this->repository = $repository;
        $this->storageService = $storageService;
    }

    public function getRepository(): ProductRepository
    {
        return $this->repository;
    }

    public function getStorageService(): DigitalFileStorageService
    {
        return $this->storageService;
    }

    // -------------------------------------------------------------------------
    // Digital Product Management
    // -------------------------------------------------------------------------

    public function createDigitalProduct(
        array $productInput,
        array $detailsInput,
        ?array $uploadedFile = null,
        ?array $uploadedImage = null
    ): int {
        $productInput['product_type'] = ProductType::DIGITAL;

        // Handle uploaded cover image if provided
        if ($uploadedImage !== null && !empty($uploadedImage['name'])) {
            $imageMeta = $this->storageService->storeImageUpload($uploadedImage);
            $productInput['cover_image_path'] = $imageMeta['file_path'];
        }

        $validatedProduct = $this->validateProductData($productInput);

        // Handle uploaded digital file if provided
        $fileMetadata = [];
        if ($uploadedFile !== null && !empty($uploadedFile['name'])) {
            $fileMetadata = $this->storageService->storeUpload($uploadedFile);
        }

        if (isset($productInput['is_membership_eligible']) && !isset($detailsInput['is_membership_eligible'])) {
            $detailsInput['is_membership_eligible'] = $productInput['is_membership_eligible'];
        }

        $mergedDetails = array_merge($detailsInput, $fileMetadata);
        $isPublishing = ($validatedProduct['status'] === ProductStatus::PUBLISHED);
        $validatedDetails = $this->validateDigitalDetails($mergedDetails, null, $isPublishing);

        $productId = $this->repository->createProduct($validatedProduct);
        if ($productId <= 0) {
            throw new RuntimeException('Failed to create digital product record.');
        }

        $this->repository->saveProductDetails($productId, $validatedDetails);

        return $productId;
    }

    public function updateDigitalProduct(
        int $id,
        array $productInput,
        array $detailsInput,
        ?array $uploadedFile = null,
        ?array $uploadedImage = null
    ): bool {
        $existing = $this->repository->findProduct($id);
        if (!$existing) {
            throw new InvalidArgumentException("Digital product with ID {$id} not found.");
        }

        $productInput['product_type'] = ProductType::DIGITAL;

        // Handle new uploaded cover image if provided
        if ($uploadedImage !== null && !empty($uploadedImage['name'])) {
            $imageMeta = $this->storageService->storeImageUpload($uploadedImage);
            $productInput['cover_image_path'] = $imageMeta['file_path'];
        } elseif (!array_key_exists('cover_image_path', $productInput)) {
            $productInput['cover_image_path'] = $existing->cover_image_path ?? null;
        }

        if (!array_key_exists('cover_image_url', $productInput)) {
            $productInput['cover_image_url'] = $existing->cover_image_url ?? null;
        }

        $validatedProduct = $this->validateProductData($productInput, $id);

        $existingDetails = (array)($this->repository->findProductDetails($id) ?? []);

        // Handle new file upload if provided
        $fileMetadata = [];
        if ($uploadedFile !== null && !empty($uploadedFile['name'])) {
            $fileMetadata = $this->storageService->storeUpload($uploadedFile);
        }

        if (isset($productInput['is_membership_eligible']) && !isset($detailsInput['is_membership_eligible'])) {
            $detailsInput['is_membership_eligible'] = $productInput['is_membership_eligible'];
        }

        $mergedDetails = array_merge($existingDetails, $detailsInput, $fileMetadata);
        $isPublishing = ($validatedProduct['status'] === ProductStatus::PUBLISHED);
        $validatedDetails = $this->validateDigitalDetails($mergedDetails, $existingDetails, $isPublishing);

        $this->repository->updateProduct($id, $validatedProduct);
        $this->repository->saveProductDetails($id, $validatedDetails);

        return true;
    }

    // -------------------------------------------------------------------------
    // Service Management
    // -------------------------------------------------------------------------

    public function createService(array $productInput, array $serviceInput, ?array $uploadedImage = null): int
    {
        $productInput['product_type'] = ProductType::SERVICE;

        // Handle uploaded cover image if provided
        if ($uploadedImage !== null && !empty($uploadedImage['name'])) {
            $imageMeta = $this->storageService->storeImageUpload($uploadedImage);
            $productInput['cover_image_path'] = $imageMeta['file_path'];
        }

        $validatedProduct = $this->validateProductData($productInput);
        $validatedService = $this->validateServiceDetails($serviceInput);

        $productId = $this->repository->createProduct($validatedProduct);
        if ($productId <= 0) {
            throw new RuntimeException('Failed to create service record.');
        }

        $this->repository->saveServiceDetails($productId, $validatedService);

        return $productId;
    }

    public function updateService(int $id, array $productInput, array $serviceInput, ?array $uploadedImage = null): bool
    {
        $existing = $this->repository->findProduct($id);
        if (!$existing) {
            throw new InvalidArgumentException("Service with ID {$id} not found.");
        }

        $productInput['product_type'] = ProductType::SERVICE;

        // Handle uploaded cover image if provided
        if ($uploadedImage !== null && !empty($uploadedImage['name'])) {
            $imageMeta = $this->storageService->storeImageUpload($uploadedImage);
            $productInput['cover_image_path'] = $imageMeta['file_path'];
        } elseif (!array_key_exists('cover_image_path', $productInput)) {
            $productInput['cover_image_path'] = $existing->cover_image_path ?? null;
        }

        if (!array_key_exists('cover_image_url', $productInput)) {
            $productInput['cover_image_url'] = $existing->cover_image_url ?? null;
        }

        $validatedProduct = $this->validateProductData($productInput, $id);
        $validatedService = $this->validateServiceDetails($serviceInput);

        $this->repository->updateProduct($id, $validatedProduct);
        $this->repository->saveServiceDetails($id, $validatedService);

        return true;
    }

    // -------------------------------------------------------------------------
    // Package / Bundle Management
    // -------------------------------------------------------------------------

    public function createPackage(array $productInput, array $packageInput = [], array $includedProductIds = []): int
    {
        $productInput['product_type'] = ProductType::PACKAGE;
        $validatedProduct = $this->validateProductData($productInput);

        // If requested to be published immediately, items are required
        if ($validatedProduct['status'] === ProductStatus::PUBLISHED && empty($includedProductIds)) {
            throw new InvalidArgumentException('A package cannot be published without at least one valid included item.');
        }

        $productId = $this->repository->createProduct($validatedProduct);
        if ($productId <= 0) {
            throw new RuntimeException('Failed to create package product record.');
        }

        $packageType = (string)($packageInput['package_type'] ?? 'bundle');
        $packageId = $this->repository->createPackage($productId, $packageType);

        if (!empty($includedProductIds)) {
            $uniqueIds = [];
            foreach ($includedProductIds as $itemPid) {
                $pid = (int)$itemPid;
                if ($pid <= 0) {
                    throw new InvalidArgumentException('Invalid product ID provided for package item.');
                }
                if (in_array($pid, $uniqueIds, true)) {
                    throw new InvalidArgumentException('Duplicate products are not allowed in the same package.');
                }
                // Validate inclusion rules
                $this->validatePackageInclusion($productId, $pid);
                $uniqueIds[] = $pid;
            }
            $this->repository->setPackageItems($packageId, $uniqueIds);
        }

        if ($validatedProduct['status'] === ProductStatus::PUBLISHED) {
            $this->validatePackageForPublishing($productId);
        }

        return $productId;
    }

    public function updatePackage(int $productId, array $productInput, array $packageInput = [], ?array $includedProductIds = null): bool
    {
        $existing = $this->repository->findProduct($productId);
        if (!$existing || $existing->product_type !== ProductType::PACKAGE) {
            throw new InvalidArgumentException("Package with ID {$productId} not found.");
        }

        $productInput['product_type'] = ProductType::PACKAGE;
        $validatedProduct = $this->validateProductData($productInput, $productId);

        $package = $this->repository->findPackageByProductId($productId);
        if (!$package) {
            $packageId = $this->repository->createPackage($productId, (string)($packageInput['package_type'] ?? 'bundle'));
            $package = $this->repository->findPackage($packageId);
        }

        if (!empty($packageInput['package_type'])) {
            $this->repository->updatePackage((int)$package->id, ['package_type' => (string)$packageInput['package_type']]);
        }

        if ($includedProductIds !== null) {
            $uniqueIds = [];
            foreach ($includedProductIds as $itemPid) {
                $pid = (int)$itemPid;
                if ($pid <= 0) {
                    throw new InvalidArgumentException('Invalid product ID provided for package item.');
                }
                if (in_array($pid, $uniqueIds, true)) {
                    throw new InvalidArgumentException('Duplicate products are not allowed in the same package.');
                }
                $this->validatePackageInclusion($productId, $pid);
                $uniqueIds[] = $pid;
            }

            if ($validatedProduct['status'] === ProductStatus::PUBLISHED && empty($uniqueIds)) {
                throw new InvalidArgumentException('Cannot leave a published package with zero items.');
            }

            $this->repository->setPackageItems((int)$package->id, $uniqueIds);
        }

        $this->repository->updateProduct($productId, $validatedProduct);

        if ($validatedProduct['status'] === ProductStatus::PUBLISHED) {
            $this->validatePackageForPublishing($productId);
        }

        return true;
    }

    public function addPackageItem(int $packageProductId, int $includedProductId, int $sortOrder = 0): bool
    {
        $package = $this->repository->findPackageByProductId($packageProductId);
        if (!$package) {
            throw new InvalidArgumentException("Package record not found for product #{$packageProductId}.");
        }

        $this->validatePackageInclusion($packageProductId, $includedProductId);

        if ($sortOrder <= 0) {
            $existing = $this->repository->getPackageItems((int)$package->id);
            $sortOrder = count($existing) + 1;
        }

        $this->repository->addPackageItem((int)$package->id, $includedProductId, $sortOrder);
        return true;
    }

    public function removePackageItem(int $packageProductId, int $includedProductId): bool
    {
        $product = $this->repository->findProduct($packageProductId);
        if (!$product || $product->product_type !== ProductType::PACKAGE) {
            throw new InvalidArgumentException("Package not found.");
        }

        $package = $this->repository->findPackageByProductId($packageProductId);
        if (!$package) {
            throw new InvalidArgumentException("Package record not found.");
        }

        // Publishing guard: cannot remove last item from published package
        if ($product->status === ProductStatus::PUBLISHED) {
            $items = $this->repository->getPackageItems((int)$package->id);
            if (count($items) <= 1) {
                throw new InvalidArgumentException('Cannot remove the last item from a published package. Unpublish or move the package to draft first.');
            }
        }

        return $this->repository->removePackageItem((int)$package->id, $includedProductId);
    }

    public function reorderPackageItems(int $packageProductId, array $orderedProductIds): bool
    {
        $package = $this->repository->findPackageByProductId($packageProductId);
        if (!$package) {
            throw new InvalidArgumentException("Package record not found.");
        }

        $order = 1;
        foreach ($orderedProductIds as $pid) {
            $this->repository->updatePackageItemSortOrder((int)$package->id, (int)$pid, $order++);
        }

        return true;
    }

    public function validatePackageInclusion(int $packageProductId, int $includedProductId): object
    {
        if ($includedProductId <= 0) {
            throw new InvalidArgumentException('Invalid product ID provided.');
        }

        if ($packageProductId > 0 && $includedProductId === $packageProductId) {
            throw new InvalidArgumentException('A package cannot include itself.');
        }

        $included = $this->repository->findProduct($includedProductId);
        if (!$included) {
            throw new InvalidArgumentException("Included product with ID {$includedProductId} does not exist.");
        }

        if ($included->product_type === ProductType::PACKAGE) {
            throw new InvalidArgumentException('A package cannot include another package.');
        }

        if ($included->product_type === ProductType::MEMBERSHIP) {
            throw new InvalidArgumentException('Membership products cannot be included in a package.');
        }

        if (!ProductType::canBeIncludedInPackage($included->product_type)) {
            throw new InvalidArgumentException("Only digital products and services may be included in a package. '{$included->product_type}' is forbidden.");
        }

        if ($included->status === ProductStatus::ARCHIVED) {
            throw new InvalidArgumentException("Archived product '{$included->title}' cannot be added to a package.");
        }

        // Check duplicate within package
        if ($packageProductId > 0) {
            $package = $this->repository->findPackageByProductId($packageProductId);
            if ($package) {
                $items = $this->repository->getPackageItems((int)$package->id);
                foreach ($items as $item) {
                    if ((int)$item->included_product_id === $includedProductId) {
                        throw new InvalidArgumentException("Product '{$included->title}' is already included in this package.");
                    }
                }
            }
        }

        return $included;
    }

    public function validatePackageForPublishing(int $packageProductId): void
    {
        $package = $this->repository->findPackageByProductId($packageProductId);
        if (!$package) {
            throw new InvalidArgumentException('Package detail record missing.');
        }

        $items = $this->repository->getPackageItemsWithProducts((int)$package->id);
        if (empty($items)) {
            throw new InvalidArgumentException('A package cannot be published without at least one valid included item.');
        }

        foreach ($items as $item) {
            if (!ProductType::canBeIncludedInPackage($item->product_type)) {
                throw new InvalidArgumentException("Cannot publish package: included item '{$item->title}' has invalid type '{$item->product_type}'.");
            }
            if ($item->status === ProductStatus::ARCHIVED) {
                throw new InvalidArgumentException("Cannot publish package: included item '{$item->title}' is archived.");
            }
        }
    }

    // -------------------------------------------------------------------------
    // Status Transitions
    // -------------------------------------------------------------------------

    public function publishProduct(int $id): bool
    {
        $product = $this->repository->findProduct($id);
        if (!$product) {
            throw new InvalidArgumentException("Product with ID {$id} not found.");
        }

        // Publishing guard for digital products: must have downloadable file/resource configured
        if ($product->product_type === ProductType::DIGITAL) {
            $details = $this->repository->findProductDetails($id);
            if (!$details || empty($details->file_path)) {
                throw new InvalidArgumentException('Cannot publish digital product: a downloadable digital file or resource must be configured first.');
            }
        }

        // Publishing guard for packages: must have >= 1 valid, non-archived item
        if ($product->product_type === ProductType::PACKAGE) {
            $this->validatePackageForPublishing($id);
        }

        return $this->repository->updateStatus($id, ProductStatus::PUBLISHED);
    }

    public function draftProduct(int $id): bool
    {
        return $this->repository->updateStatus($id, ProductStatus::DRAFT);
    }

    public function archiveProduct(int $id): bool
    {
        return $this->repository->updateStatus($id, ProductStatus::ARCHIVED);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function validateProductData(array $input, ?int $id = null): array
    {
        // 1. Title
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Product title cannot be empty.');
        }
        if (mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Product title cannot exceed 255 characters.');
        }

        // 2. Slug
        $rawSlug = trim((string)($input['slug'] ?? ''));
        $slug = $rawSlug !== '' ? $this->sanitizeSlug($rawSlug) : $this->generateSlug($title);
        if ($slug === '') {
            throw new InvalidArgumentException('A valid slug is required.');
        }

        // Check slug uniqueness
        $existing = $this->repository->findProductBySlug($slug, $id);
        if ($existing) {
            throw new InvalidArgumentException("Slug '{$slug}' is already in use by another product.");
        }

        // 3. Product Type
        $type = (string)($input['product_type'] ?? ProductType::DIGITAL);
        if (!ProductType::isValid($type)) {
            throw new InvalidArgumentException("Invalid product type '{$type}'.");
        }

        // 4. Status
        $status = (string)($input['status'] ?? ProductStatus::DRAFT);
        if (!ProductStatus::isValid($status)) {
            throw new InvalidArgumentException("Invalid product status '{$status}'.");
        }

        // 5. Pricing
        $isFree = !empty($input['is_free']);
        $originalPrice = (float)($input['original_price'] ?? 0);
        if ($originalPrice < 0) {
            throw new InvalidArgumentException('Original price cannot be negative.');
        }

        $discountPercent = (float)($input['discount_percent'] ?? 0);
        if ($discountPercent < 0 || $discountPercent > 100) {
            throw new InvalidArgumentException('Discount percentage must be between 0 and 100.');
        }

        // Deterministic derivation of final_price
        $finalPrice = ProductPricingCalculator::deriveFinalPrice(
            $originalPrice,
            $discountPercent,
            $isFree
        );

        $coverImageUrl = null;
        if (!empty($input['cover_image_url'])) {
            $coverImageUrl = $this->storageService->validateSafeUrl((string)$input['cover_image_url']);
        }

        $coverImagePath = !empty($input['cover_image_path']) ? trim((string)$input['cover_image_path']) : null;

        return [
            'title'            => $title,
            'slug'             => $slug,
            'description'      => !empty($input['description']) ? trim((string)$input['description']) : null,
            'cover_image_path' => $coverImagePath,
            'cover_image_url'  => $coverImageUrl,
            'product_type'     => $type,
            'status'           => $status,
            'original_price'   => number_format($originalPrice, 2, '.', ''),
            'discount_percent' => number_format($discountPercent, 2, '.', ''),
            'final_price'      => $finalPrice,
            'currency'         => 'BDT',
            'is_free'          => $isFree ? 1 : 0,
        ];
    }

    public function validateDigitalDetails(array $input, ?array $existing = null, bool $isPublishing = false): array
    {
        $version = trim((string)($input['version'] ?? ($existing['version'] ?? '1.0.0')));
        if ($version === '') {
            $version = '1.0.0';
        }

        $maxDownloads = (int)($input['max_downloads'] ?? ($existing['max_downloads'] ?? 3));
        if ($maxDownloads < 0) {
            throw new InvalidArgumentException('Download limit cannot be negative.');
        }

        $expiryDays = (int)($input['download_expiry_days'] ?? ($existing['download_expiry_days'] ?? 0));
        if ($expiryDays < 0) {
            throw new InvalidArgumentException('Download expiry days cannot be negative.');
        }

        $resourceType = strtolower(trim((string)($input['resource_type'] ?? ($existing['resource_type'] ?? 'file'))));
        if (!in_array($resourceType, ['file', 'url', 'both'], true)) {
            $resourceType = 'file';
        }

        $resourceUrl = null;
        if (!empty($input['resource_url'])) {
            $resourceUrl = $this->storageService->validateSafeUrl((string)$input['resource_url']);
        } elseif (!empty($existing['resource_url'])) {
            $resourceUrl = (string)$existing['resource_url'];
        }

        $filePath = (string)($input['file_path'] ?? ($existing['file_path'] ?? ''));
        $fileName = (string)($input['file_name'] ?? ($existing['file_name'] ?? ''));
        $fileHash = (string)($input['file_hash'] ?? ($existing['file_hash'] ?? ''));
        $fileSize = (int)($input['file_size'] ?? ($existing['file_size'] ?? 0));
        $mimeType = (string)($input['mime_type'] ?? ($existing['mime_type'] ?? ''));

        // If product is being published, validate resource availability based on resource_type
        if ($isPublishing) {
            $hasValidFile = false;
            if ($filePath !== '') {
                $baseRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 4);
                $absolutePath = (str_starts_with($filePath, 'storage/') ? $baseRoot . '/' . $filePath : $filePath);
                $hasValidFile = file_exists($absolutePath);
            }

            $hasValidUrl = (!empty($resourceUrl));

            if ($resourceType === 'file' && !$hasValidFile) {
                throw new InvalidArgumentException('Digital product must have a valid uploaded file before it can be published.');
            }

            if ($resourceType === 'url' && !$hasValidUrl) {
                throw new InvalidArgumentException('Digital product must have a valid external resource URL before it can be published.');
            }

            if ($resourceType === 'both') {
                if (!$hasValidFile || !$hasValidUrl) {
                    throw new InvalidArgumentException('Digital product with resource type "both" must have both an uploaded file and an external resource URL before it can be published.');
                }
            }
        }

        return [
            'version'                => $version,
            'resource_type'          => $resourceType,
            'resource_url'           => $resourceUrl,
            'file_path'              => $filePath !== '' ? $filePath : null,
            'file_name'              => $fileName !== '' ? $fileName : null,
            'file_hash'              => $fileHash !== '' ? $fileHash : null,
            'file_size'              => $fileSize,
            'mime_type'              => $mimeType !== '' ? $mimeType : null,
            'max_downloads'          => $maxDownloads,
            'download_expiry_days'   => $expiryDays,
            'is_membership_eligible' => !empty($input['is_membership_eligible']) ? 1 : 0,
        ];
    }

    public function validateServiceDetails(array $input): array
    {
        $deliveryDays = (int)($input['delivery_time_days'] ?? ($input['delivery_days'] ?? 1));
        if ($deliveryDays < 1) {
            throw new InvalidArgumentException('Delivery time must be at least 1 day.');
        }

        $scope = trim((string)($input['service_scope'] ?? ($input['scope_description'] ?? '')));
        $requirements = trim((string)($input['requirements_prompt'] ?? ''));

        return [
            'delivery_time_days'  => $deliveryDays,
            'service_scope'       => $scope !== '' ? $scope : null,
            'requirements_prompt' => $requirements !== '' ? $requirements : null,
        ];
    }

    public function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    public function generateSlug(string $title): string
    {
        return $this->sanitizeSlug($title);
    }

    public function findProductWithDetails(int $id): ?array
    {
        $product = $this->repository->findProduct($id);
        if (!$product) {
            return null;
        }

        $details = null;
        if ($product->product_type === ProductType::DIGITAL) {
            $details = $this->repository->findProductDetails($id);
        } elseif ($product->product_type === ProductType::SERVICE) {
            $details = $this->repository->findServiceDetails($id);
        }

        return [
            'product' => $product,
            'details' => $details,
        ];
    }
}
