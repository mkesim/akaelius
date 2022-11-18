<?php

namespace Admin\Models;

use Admin\Traits\Locationable;
use Igniter\Flame\Database\Traits\Validation;
use Igniter\Flame\Exception\ApplicationException;
use Illuminate\Support\Collection;

class DiningArea extends \Igniter\Flame\Database\Model
{
    use Locationable;
    use Validation;

    public $table = 'dining_areas';

    public $timestamps = true;

    protected $casts = [
        'floor_plan' => 'array',
    ];

    /**
     * @var array Relations
     */
    public $relation = [
        'hasMany' => [
            'dining_sections' => [DiningSection::class, 'foreignKey' => 'location_id', 'otherKey' => 'location_id'],
            'dining_tables' => [DiningTable::class, 'delete' => true],
            'dining_table_solos' => [DiningTable::class, 'scope' => 'whereIsNotCombo'],
            'dining_table_combos' => [DiningTable::class, 'scope' => 'whereIsCombo'],
            'available_tables' => [DiningTable::class, 'scope' => 'whereIsRoot'],
        ],
        'belongsTo' => [
            'location' => [Locations_model::class],
        ],
    ];

    public $rules = [
        ['name', 'admin::lang.label_name', 'required|between:2,128'],
    ];

    public static function getDropdownOptions()
    {
        return static::dropdown('name');
    }

    public function getTablesForFloorPlan()
    {
        return $this->available_tables->map(function ($diningTable) {
            return $diningTable->toFloorPlanArray();
        });
    }

    public function getDiningTablesWithReservation($reservations)
    {
        return $this->available_tables
            ->map(function ($diningTable) use ($reservations) {
                $reservation = $reservations->first(function ($reservation) use ($diningTable) {
                    return $reservation->tables->where('id', $diningTable->id)->count() > 0;
                });

                return $diningTable->toFloorPlanArray($reservation);
            });
    }

    //
    // Events
    //

    //
    // Accessors & Mutators
    //

    public function getDiningTableCountAttribute($value)
    {
        return $this->available_tables->count();
    }

    public function scopeWhereIsActive($query)
    {
        return $query->whereIsRoot()->where('is_active', 1);
    }

    //
    // Helpers
    //

    public function duplicate()
    {
        $newDiningArea = $this->replicate();
        $newDiningArea->name .= ' (copy)';
        $newDiningArea->save();

        $this->dining_tables
            ->filter(function ($table) {
                return !$table->is_combo;
            })
            ->each(function ($table) use ($newDiningArea) {
                $newTable = $table->replicate();
                $newTable->dining_area_id = $newDiningArea->getKey();
                $newTable->save();
            });

        return $newDiningArea;
    }

    public function createCombo(Collection $tables)
    {
        $firstTable = $tables->first();
        $tableNames = $tables->pluck('name')->join('/');

        if ($tables->filter(function ($table) {
            return $table->parent !== null;
        })->isNotEmpty())
            throw new ApplicationException(lang('admin::lang.dining_areas.alert_table_already_combined'));

        if ($tables->pluck('dining_section_id')->unique()->count() > 1)
            throw new ApplicationException(lang('admin::lang.dining_areas.alert_table_combo_section_mismatch'));

        $comboTable = $this->dining_tables()->create([
            'name' => $tableNames,
            'shape' => $firstTable->shape,
            'dining_area_id' => $firstTable->dining_area_id,
            'dining_section_id' => $firstTable->dining_section_id,
            'min_capacity' => $tables->sum('min_capacity'),
            'max_capacity' => $tables->sum('max_capacity'),
            'is_combo' => true,
            'is_enabled' => true,
        ]);

        $tables->each(function ($table) use ($comboTable) {
            $table->parent()->associate($comboTable)->saveQuietly();
        });

        $comboTable::fixBrokenTreeQuietly();

        return $comboTable;
    }
}