<?php

namespace App\Services;

use App\Models\CrmNotification;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\Employee;
use App\Models\Quotation;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleVersion;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    public function __construct(
        private readonly CommercialItemResolver $items,
        private readonly OrderService $orders,
        private readonly CrmNotificationService $notifications,
        private readonly BusinessSettingsService $settings,
        private readonly CommercialEmployeeContext $commercialContext,
    ) {}

    public function create(array $data, User $user): Quotation
    {
        return DB::transaction(function () use ($data, $user) {
            [$branch, $seller] = $this->commercialContext->adminAssignment(
                (int) $data['branch_id'],
                isset($data['sales_employee_id']) ? (int) $data['sales_employee_id'] : null,
            );

            return $this->load($this->createAssigned($data, $user, $branch->id, $seller?->id));
        });
    }

    public function createPersonal(array $data, User $user): Quotation
    {
        return DB::transaction(function () use ($data, $user) {
            $context = $this->commercialContext->resolve($user, true);

            return $this->loadPersonal($this->createAssigned(
                $data,
                $user,
                $context['branch']->id,
                $context['employee']->id,
            ));
        });
    }

    public function update(Quotation $quotation, array $data, User $user): Quotation
    {
        return DB::transaction(function () use ($quotation, $data, $user) {
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            $this->expireIfNeeded($locked, $user);
            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['status' => 'La cotización ya no puede editarse.']);
            }

            $branchId = array_key_exists('branch_id', $data) ? (int) $data['branch_id'] : $locked->branch_id;
            $sellerId = array_key_exists('sales_employee_id', $data)
                ? ($data['sales_employee_id'] === null ? null : (int) $data['sales_employee_id'])
                : $locked->sales_employee_id;

            if ($locked->status !== Quotation::STATUS_DRAFT) {
                $errors = [];
                if ((int) $branchId !== (int) $locked->branch_id) {
                    $errors['branch_id'] = 'La sede de una cotización enviada no puede modificarse.';
                }
                if ($sellerId !== $locked->sales_employee_id) {
                    $errors['sales_employee_id'] = 'El vendedor de una cotización enviada no puede modificarse.';
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            } else {
                [$branch, $seller] = $this->commercialContext->adminAssignment(
                    (int) $branchId,
                    $sellerId,
                );
                $branchId = $branch->id;
                $sellerId = $seller?->id;
            }

            return $this->load($this->updateDocument($locked, $data, $user, (int) $branchId, $sellerId));
        });
    }

    public function updatePersonal(Quotation $quotation, array $data, User $user): Quotation
    {
        return DB::transaction(function () use ($quotation, $data, $user) {
            $context = $this->commercialContext->resolve($user);
            $locked = Quotation::query()
                ->whereKey($quotation->id)
                ->where('sales_employee_id', $context['employee']->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedContext = $this->commercialContext->resolveForBranch(
                $user,
                (int) $locked->branch_id,
                true,
            );
            if ($lockedContext['employee']->id !== $context['employee']->id) {
                throw (new ModelNotFoundException)->setModel(Quotation::class, [$quotation->id]);
            }
            $this->expireIfNeeded($locked, $user);
            if ($locked->status !== Quotation::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Solo una cotización en borrador puede editarse desde el portal personal.']);
            }

            return $this->loadPersonal($this->updateDocument(
                $locked,
                $data,
                $user,
                (int) $locked->branch_id,
                $locked->sales_employee_id,
            ));
        });
    }

    public function transition(Quotation $quotation, string $status, User $user, ?string $reason = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $status, $user, $reason) {
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            $this->expireIfNeeded($locked, $user);
            $allowed = [
                Quotation::STATUS_DRAFT => [Quotation::STATUS_SENT],
                Quotation::STATUS_SENT => [Quotation::STATUS_REJECTED],
            ];
            if (! in_array($status, $allowed[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'La transición de estado solicitada no es válida.']);
            }

            $this->recordStatus($locked, $status, $user, $reason);

            return $this->load($locked->fresh());
        });
    }

    public function sendPersonal(Quotation $quotation, User $user): Quotation
    {
        return DB::transaction(function () use ($quotation, $user) {
            $context = $this->commercialContext->resolve($user);
            $locked = Quotation::query()
                ->whereKey($quotation->id)
                ->where('sales_employee_id', $context['employee']->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedContext = $this->commercialContext->resolveForBranch(
                $user,
                (int) $locked->branch_id,
                true,
            );
            if ($lockedContext['employee']->id !== $context['employee']->id) {
                throw (new ModelNotFoundException)->setModel(Quotation::class, [$quotation->id]);
            }
            $this->expireIfNeeded($locked, $user);
            if ($locked->status !== Quotation::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Solo una cotización en borrador puede enviarse.']);
            }

            $this->recordStatus($locked, Quotation::STATUS_SENT, $user);

            return $this->loadPersonal($locked->fresh());
        });
    }

    public function convert(Quotation $quotation, User $user): Quotation
    {
        $converted = DB::transaction(function () use ($quotation, $user) {
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            $this->commercialContext->activeBranch((int) $locked->branch_id, true);

            return $this->load($this->convertLocked($locked, $user));
        });

        $this->notifyConverted($converted);

        return $converted;
    }

    public function convertPersonal(Quotation $quotation, User $user): Quotation
    {
        $converted = DB::transaction(function () use ($quotation, $user) {
            $context = $this->commercialContext->resolve($user);
            $locked = Quotation::query()
                ->whereKey($quotation->id)
                ->where('sales_employee_id', $context['employee']->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedContext = $this->commercialContext->resolveForBranch(
                $user,
                (int) $locked->branch_id,
                true,
            );
            if ($lockedContext['employee']->id !== $context['employee']->id) {
                throw (new ModelNotFoundException)->setModel(Quotation::class, [$quotation->id]);
            }

            return $this->loadPersonal($this->convertLocked($locked, $user));
        });

        $this->notifyConverted($converted);

        return $converted;
    }

    public function materializeExpired(): void
    {
        $this->materializeExpiredQuery(Quotation::query());
    }

    public function materializeExpiredForEmployee(Employee $employee): void
    {
        $this->materializeExpiredQuery(
            Quotation::query()->where('sales_employee_id', $employee->id),
        );
    }

    private function materializeExpiredQuery(Builder $query): void
    {
        $query
            ->whereIn('status', [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT])
            ->whereDate('valid_until', '<', BusinessContext::today()->toDateString())
            ->select('id')
            ->eachById(function (Quotation $quotation) {
                DB::transaction(function () use ($quotation) {
                    $locked = Quotation::query()->lockForUpdate()->find($quotation->id);
                    if ($locked) {
                        $this->expireIfNeeded($locked);
                    }
                });
            });
    }

    public function expireIfNeeded(Quotation $quotation, ?User $user = null): void
    {
        if (
            in_array($quotation->status, [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT], true)
            && $quotation->valid_until?->lt(BusinessContext::today())
        ) {
            $this->recordStatus($quotation, Quotation::STATUS_EXPIRED, $user, 'Vigencia finalizada.');
        }
    }

    public function applyFilters(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return $query
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $match) use ($search) {
                    $like = "%{$search}%";
                    $match->where('quotation_number', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('customer_phone', 'like', $like)
                        ->orWhere('customer_email', 'like', $like);
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn (Builder $query, int $id) => $query->where('customer_id', $id))
            ->when($filters['created_by'] ?? null, fn (Builder $query, int $id) => $query->where('created_by', $id))
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $id) => $query->where('branch_id', $id))
            ->when($filters['sales_employee_id'] ?? null, fn (Builder $query, int $id) => $query->where('sales_employee_id', $id));
    }

    public function applyPersonalFilters(Builder $query, array $filters): Builder
    {
        $this->applyFilters($query, $filters);

        if ($filters['date_from'] ?? null) {
            $query->where(
                'created_at',
                '>=',
                CarbonImmutable::parse($filters['date_from'], BusinessContext::TIMEZONE)->startOfDay()->utc(),
            );
        }
        if ($filters['date_to'] ?? null) {
            $query->where(
                'created_at',
                '<',
                CarbonImmutable::parse($filters['date_to'], BusinessContext::TIMEZONE)->addDay()->startOfDay()->utc(),
            );
        }

        return $query;
    }

    public function load(Quotation $quotation): Quotation
    {
        return $quotation->load([
            'branch:id,code,name,city,is_active',
            'salesEmployee:id,branch_id,name,is_active',
            'salesEmployee.branch:id,code,name,city,is_active',
            'customer', 'customerVehicle.vehicleBrand', 'customerVehicle.vehicleModel',
            'customerVehicle.vehicleVersion', 'items.product', 'items.productVariant', 'items.service.category',
            'creator', 'updater', 'converter', 'statusHistory.changedBy', 'order',
        ]);
    }

    public function loadPersonal(Quotation $quotation): Quotation
    {
        return $quotation->unsetRelations()->load([
            'branch:id,code,name,city,is_active',
            'salesEmployee:id,branch_id,name,is_active',
            'items',
            'order:id,order_number,status',
        ]);
    }

    private function createAssigned(array $data, User $user, int $branchId, ?int $sellerId): Quotation
    {
        $items = $this->items->resolve($data['items'], true);
        $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : null;
        $customerVehicle = isset($data['customer_vehicle_id'])
            ? CustomerVehicle::with(['customer', 'vehicleBrand', 'vehicleModel', 'vehicleVersion'])->find($data['customer_vehicle_id'])
            : null;
        $this->validateCustomerVehicle($customer, $customerVehicle);

        $quotation = Quotation::create([
            'quotation_number' => $this->generateNumber(),
            'status' => Quotation::STATUS_DRAFT,
            'branch_id' => $branchId,
            'sales_employee_id' => $sellerId,
            'valid_until' => $data['valid_until'] ?? BusinessContext::today()->addDays($this->settings->quotationValidityDays()),
            'customer_id' => $customer?->id,
            'customer_vehicle_id' => $customerVehicle?->id,
            ...$this->resolveCustomer($data, $customer),
            ...$this->resolveVehicle($data, $customerVehicle),
            ...$this->totals($items),
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $quotation->items()->createMany($items);
        $quotation->statusHistory()->create([
            'from_status' => null,
            'to_status' => Quotation::STATUS_DRAFT,
            'changed_by' => $user->id,
        ]);

        return $quotation->fresh();
    }

    private function updateDocument(
        Quotation $quotation,
        array $data,
        User $user,
        int $branchId,
        ?int $sellerId,
    ): Quotation {
        $createdDate = CarbonImmutable::parse($quotation->created_at)
            ->setTimezone(BusinessContext::TIMEZONE)
            ->startOfDay();
        if (
            isset($data['valid_until'])
            && CarbonImmutable::parse($data['valid_until'], BusinessContext::TIMEZONE)->startOfDay()->lt($createdDate)
        ) {
            throw ValidationException::withMessages([
                'valid_until' => 'La vigencia no puede ser anterior a la creación de la cotización.',
            ]);
        }

        $items = $this->items->resolve($data['items'], true);
        $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : null;
        $customerVehicle = isset($data['customer_vehicle_id'])
            ? CustomerVehicle::with(['customer', 'vehicleBrand', 'vehicleModel', 'vehicleVersion'])->find($data['customer_vehicle_id'])
            : null;
        $this->validateCustomerVehicle($customer, $customerVehicle);

        $quotation->update([
            'branch_id' => $branchId,
            'sales_employee_id' => $sellerId,
            'valid_until' => $data['valid_until'] ?? $quotation->valid_until,
            'customer_id' => $customer?->id,
            'customer_vehicle_id' => $customerVehicle?->id,
            ...$this->resolveCustomer($data, $customer),
            ...$this->resolveVehicle($data, $customerVehicle),
            ...$this->totals($items),
            'notes' => $data['notes'] ?? null,
            'updated_by' => $user->id,
        ]);
        $quotation->items()->delete();
        $quotation->items()->createMany($items);

        return $quotation->fresh();
    }

    private function convertLocked(Quotation $quotation, User $user): Quotation
    {
        $this->expireIfNeeded($quotation, $user);
        if (
            ! in_array($quotation->status, [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT], true)
            || $quotation->order_id
        ) {
            throw ValidationException::withMessages([
                'status' => 'La cotización no puede convertirse a venta.',
            ]);
        }
        if (! $quotation->branch_id) {
            throw ValidationException::withMessages([
                'branch_id' => 'La cotización debe tener una sede antes de convertirse.',
            ]);
        }

        $this->validatePersistedRelations($quotation);
        $quotation->load('items');

        $order = $this->orders->createFromQuotation($quotation, $user);
        $from = $quotation->status;
        $quotation->update([
            'status' => Quotation::STATUS_CONVERTED,
            'order_id' => $order->id,
            'converted_at' => now(),
            'converted_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $quotation->statusHistory()->create([
            'from_status' => $from,
            'to_status' => Quotation::STATUS_CONVERTED,
            'changed_by' => $user->id,
        ]);

        return $quotation->fresh();
    }

    private function notifyConverted(Quotation $quotation): void
    {
        try {
            $this->notifications->distribute('quotations.view', [
                'type' => CrmNotification::TYPE_QUOTATION_CONVERTED,
                'severity' => CrmNotification::SEVERITY_SUCCESS,
                'title' => 'Cotización convertida',
                'message' => "La cotización {$quotation->quotation_number} se convirtió en venta.",
                'data' => [
                    'quotation_id' => $quotation->id,
                    'quotation_number' => $quotation->quotation_number,
                    'order_id' => $quotation->order_id,
                    'order_number' => $quotation->order?->order_number,
                ],
                'reference_type' => 'quotation',
                'reference_id' => $quotation->id,
                'dedupe_key' => "quotation_converted:{$quotation->id}",
            ]);
        } catch (\Throwable $exception) {
            Log::warning('No se pudo crear la notificación CRM de cotización convertida.', [
                'quotation_id' => $quotation->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function recordStatus(Quotation $quotation, string $status, ?User $user, ?string $reason = null): void
    {
        $from = $quotation->status;
        if ($from === $status) {
            return;
        }
        $quotation->update(['status' => $status, 'updated_by' => $user?->id ?? $quotation->updated_by]);
        $quotation->statusHistory()->create([
            'from_status' => $from,
            'to_status' => $status,
            'changed_by' => $user?->id,
            'reason' => $reason,
        ]);
    }

    private function validateCustomerVehicle(?Customer $customer, ?CustomerVehicle $vehicle): void
    {
        if ($vehicle && ! $customer) {
            throw ValidationException::withMessages(['customer_vehicle_id' => 'Debes indicar el cliente propietario del vehículo.']);
        }
        if ($vehicle && (int) $vehicle->customer_id !== $customer?->id) {
            throw ValidationException::withMessages(['customer_vehicle_id' => 'El vehículo no pertenece al cliente indicado.']);
        }
    }

    private function validatePersistedRelations(Quotation $quotation): void
    {
        $customer = $quotation->customer_id ? Customer::find($quotation->customer_id) : null;
        $vehicle = $quotation->customer_vehicle_id ? CustomerVehicle::find($quotation->customer_vehicle_id) : null;
        if ($quotation->customer_id && ! $customer) {
            throw ValidationException::withMessages(['customer_id' => 'El cliente de la cotización ya no existe.']);
        }
        if ($quotation->customer_vehicle_id && ! $vehicle) {
            throw ValidationException::withMessages(['customer_vehicle_id' => 'El vehículo de la cotización ya no existe.']);
        }
        $this->validateCustomerVehicle($customer, $vehicle);
    }

    private function resolveCustomer(array $data, ?Customer $customer): array
    {
        if ($customer) {
            return [
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_phone' => $customer->phone,
                'customer_document' => $customer->document,
                'customer_city' => $customer->city,
                'customer_address' => $customer->address,
                'customer_notes' => $data['customer_notes'] ?? $customer->notes,
            ];
        }

        return [
            'customer_name' => $data['customer_name'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'customer_document' => $data['customer_document'] ?? null,
            'customer_city' => $data['customer_city'] ?? null,
            'customer_address' => $data['customer_address'] ?? null,
            'customer_notes' => $data['customer_notes'] ?? null,
        ];
    }

    private function resolveVehicle(array $data, ?CustomerVehicle $vehicle): array
    {
        if ($vehicle) {
            return [
                'vehicle_brand_id' => $vehicle->vehicle_brand_id,
                'vehicle_model_id' => $vehicle->vehicle_model_id,
                'vehicle_version_id' => $vehicle->vehicle_version_id,
                'vehicle_brand_name' => $vehicle->vehicleBrand?->name,
                'vehicle_model_name' => $vehicle->vehicleModel?->name,
                'vehicle_version_name' => $vehicle->vehicleVersion?->display_name,
                'vehicle_year' => $vehicle->year,
                'vehicle_plate' => $vehicle->plate,
                'vehicle_vin' => $vehicle->vin,
                'vehicle_color' => $vehicle->color,
                'vehicle_notes' => $vehicle->notes,
            ];
        }

        $brand = isset($data['vehicle_brand_id']) ? VehicleBrand::find($data['vehicle_brand_id']) : null;
        $model = isset($data['vehicle_model_id']) ? VehicleModel::find($data['vehicle_model_id']) : null;
        $version = isset($data['vehicle_version_id']) ? VehicleVersion::find($data['vehicle_version_id']) : null;
        if ($model && (! $brand || $model->vehicle_brand_id !== $brand->id)) {
            throw ValidationException::withMessages(['vehicle_model_id' => 'El modelo no pertenece a la marca indicada.']);
        }
        if ($version && (! $model || $version->vehicle_model_id !== $model->id)) {
            throw ValidationException::withMessages(['vehicle_version_id' => 'La versión no pertenece al modelo indicado.']);
        }

        return [
            'vehicle_brand_id' => $brand?->id,
            'vehicle_model_id' => $model?->id,
            'vehicle_version_id' => $version?->id,
            'vehicle_brand_name' => $brand?->name,
            'vehicle_model_name' => $model?->name,
            'vehicle_version_name' => $version?->display_name,
            'vehicle_year' => $data['vehicle_year'] ?? null,
            'vehicle_plate' => $data['vehicle_plate'] ?? null,
            'vehicle_vin' => $data['vehicle_vin'] ?? null,
            'vehicle_color' => $data['vehicle_color'] ?? null,
            'vehicle_notes' => $data['vehicle_notes'] ?? null,
        ];
    }

    private function totals(array $items): array
    {
        return [
            'subtotal' => array_sum(array_column($items, 'subtotal')),
            'discount_total' => array_sum(array_column($items, 'discount_amount')),
            'total' => array_sum(array_column($items, 'total')),
        ];
    }

    private function generateNumber(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $number = 'COT-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
            if (! Quotation::where('quotation_number', $number)->exists()) {
                return $number;
            }
        }

        throw ValidationException::withMessages(['quotation_number' => 'No fue posible generar el número de cotización.']);
    }
}
