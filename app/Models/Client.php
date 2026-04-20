<?php

namespace App\Models;

use App\Enums\DepartmentEnum;
use App\Enums\MunicipalityEnum;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Client extends Model
{
    use HasFactory;

    protected $table = 'clients';

    protected $fillable = [
        'first_name',
        'last_name',
        'phone_number',
        'business_name',
        'address',
        'department',
        'township',
        'employee_id',
        'type_price_id',
    ];
    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function cast(): array
    {
        return [
            'department' => DepartmentEnum::class,
            'township' => MunicipalityEnum::getByDepartment(DepartmentEnum::from($this->department)),
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function typePrice(): BelongsTo
    {
        return $this->belongsTo(TypePrice::class);
    }

    /**
     * Define a polymorphic relationship with the location model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne
     */
    public function location(): MorphOne
    {
        return $this->morphOne(Location::class, 'model');
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get the profile photo of the client with polimorphic relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne
     */
    public function profileImage(): MorphOne
    {
        return $this->morphOne(ResourceMedia::class, 'model')->where('type', 'profile');
    }

    /**
     * Get the images of the client with polimorphic relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function businessImages(): MorphMany
    {
        return $this->morphMany(ResourceMedia::class, 'model')->where('type', 'business');
    }

    /**
     * Get the visit days for the client.
     */
    public function visitDays(): HasMany
    {
        return $this->hasMany(ClientVisitDay::class);
    }

    public function clientVisits(): HasMany
    {
        return $this->hasMany(ClientVisit::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Get the accounts receivable for the client through sales relationship
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function accountReceivable()
    {
        return $this->hasManyThrough(
            AccountReceivable::class,
            Sale::class,
            'client_id', // Foreign key on sales table
            'sales_id',   // Foreign key on account_receivables table
            'id',        // Local key on clients table
            'id'         // Local key on sales table
        );
    }

    /**
     * Scope a query to only include clients for a especific day.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $day
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithVisitDaysForDay($query, $day = null): Builder
    {
        if (!$day)
            return $query->with('visitDays');

        return $query->with([
            'visitDays' => function ($query) use ($day) {
                $query->where('visit_day', $day);
            }
        ]);
    }

    /**
     * Scope a query to include only clients that have a pending visit for the current week up to the given day.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $day
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWherePendingVisitForWeek($query, $day = null): Builder
    {
        if (!$day)
            return $query;

        $daysMap = [
            'Lunes' => 1,
            'Martes' => 2,
            'Miércoles' => 3,
            'Jueves' => 4,
            'Viernes' => 5,
            'Sábado' => 6,
            'Domingo' => 7,
        ];

        $dayIndex = $daysMap[$day] ?? 0;
        $startOfWeek = Carbon::now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $endOfWeek = Carbon::now()->endOfWeek(Carbon::SUNDAY)->toDateString();

        return $query->whereHas('visitDays', function ($q) use ($dayIndex, $startOfWeek, $endOfWeek) {
            // Map visit_day to index
            $q->whereRaw("
                CASE visit_day
                    WHEN 'Lunes' THEN 1
                    WHEN 'Martes' THEN 2
                    WHEN 'Miércoles' THEN 3
                    WHEN 'Jueves' THEN 4
                    WHEN 'Viernes' THEN 5
                    WHEN 'Sábado' THEN 6
                    WHEN 'Domingo' THEN 7
                    ELSE 8
                END <= ?
            ", [$dayIndex])
                ->whereNotExists(function ($sub) use ($startOfWeek, $endOfWeek) {
                    $sub->select(DB::raw(1))
                        ->from('client_visits')
                        ->whereColumn('client_visits.client_id', 'client_visit_days.client_id')
                        ->where('client_visits.visited_date', '>=', DB::raw("DATE_ADD('$startOfWeek', INTERVAL (
                        CASE client_visit_days.visit_day
                            WHEN 'Lunes' THEN 0
                            WHEN 'Martes' THEN 1
                            WHEN 'Miércoles' THEN 2
                            WHEN 'Jueves' THEN 3
                            WHEN 'Viernes' THEN 4
                            WHEN 'Sábado' THEN 5
                            WHEN 'Domingo' THEN 6
                            ELSE 0
                        END
                    ) DAY)"))
                        ->where('client_visits.visited_date', '<=', $endOfWeek);
                });
        });
    }

    public function scopeOrderByVisitDay($query, $day): Builder
    {
        if (!$day)
            return $query;

        // Use left join to preserve clients that don't have a visit specifically on $day
        // (e.g. they are showing up because they missed a previous day)
        return $query->leftJoin('client_visit_days', function ($join) use ($day) {
            $join->on('clients.id', '=', 'client_visit_days.client_id')
                ->where('client_visit_days.visit_day', '=', $day);
        })
            ->select('clients.*')
            ->orderByRaw('client_visit_days.position IS NULL, client_visit_days.position ASC');
    }

    // Scopes
    public function scopeWithLocationData($query)
    {
        return $query->with(['location', 'employee.branch'])
            ->has('location');
    }

    public function getMapDataAttribute(): array
    {
        return [
            'id' => $this->id,
            'tipo' => 'cliente',
            'nombre' => $this->full_name,
            'direccion' => $this->address,
            'department' => $this->department,
            'township' => $this->township,
            'phone_number' => $this->phone_number,
            'empleado' => $this->employee?->full_name ?? 'Sin asignar',
            'employee_id' => $this->employee_id,
            'has_location' => $this->location !== null,
            'location' => $this->when($this->location, fn() => [
                'lat' => $this->location->latitude,
                'lng' => $this->location->longitude,
                'maps_url' => $this->location->google_maps_url,
                'whatsapp_url' => $this->whatsapp_share_url
            ])
        ];
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(ClientEquipment::class);
    }

    public function getWhatsappShareUrlAttribute(): string
    {
        if (!$this->location)
            return '';

        $message = "*Información del Cliente*\n" .
            "Nombre: {$this->full_name}\n" .
            "Dirección: {$this->address}\n" .
            "Departamento: {$this->department}\n" .
            "Municipio: {$this->township}\n" .
            "Teléfono: {$this->phone_number}\n" .
            "Ubicación: {$this->location->google_maps_url}\n" .
            "Empleado Asignado: " . ($this->employee?->full_name ?? "Sin asignar");

        return "https://wa.me/?text=" . urlencode($message);
    }

    public function getProfileImageUrlAttribute(): string
    {
        Log::info("Profile Image: {$this->profileImage}");
        return $this->profileImage ? asset('storage/' . $this->profileImage->path) : asset('images/avatar.png');
    }
}
