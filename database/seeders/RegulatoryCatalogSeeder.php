<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\FeatureOption;
use App\Models\Scope;
use Illuminate\Database\Seeder;

class RegulatoryCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog()['scopes'] as $scopeData) {
            /** @var Scope $scope */
            $scope = Scope::query()->updateOrCreate(
                ['code' => $scopeData['code']],
                [
                    'is_active' => true,
                    'sort_order' => $scopeData['sort_order'],
                ]
            );

            foreach ($scopeData['translations'] as $locale => $translation) {
                $scope->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'name' => $translation['name'],
                        'description' => $translation['description'] ?? null,
                    ]
                );
            }

            foreach ($scopeData['features'] ?? [] as $featureData) {
                /** @var Feature $feature */
                $feature = Feature::query()->updateOrCreate(
                    ['code' => $featureData['code']],
                    [
                        'scope_id' => $scope->id,
                        'data_type' => $featureData['data_type'],
                        'is_active' => true,
                        'sort_order' => $featureData['sort_order'],
                    ]
                );

                foreach ($featureData['translations'] as $locale => $translation) {
                    $feature->translations()->updateOrCreate(
                        ['locale' => $locale],
                        [
                            'label' => $translation['label'],
                            'help_text' => $translation['help_text'] ?? null,
                        ]
                    );
                }

                foreach ($featureData['options'] ?? [] as $optionData) {
                    /** @var FeatureOption $option */
                    $option = $feature->options()->updateOrCreate(
                        ['code' => $optionData['code']],
                        ['sort_order' => $optionData['sort_order']]
                    );

                    foreach ($optionData['translations'] as $locale => $translation) {
                        $option->translations()->updateOrCreate(
                            ['locale' => $locale],
                            ['label' => $translation['label']]
                        );
                    }
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(): array
    {
        return [
            'scopes' => [
                [
                    'code' => 'environment_industrial_safety',
                    'sort_order' => 1,
                    'translations' => [
                        'en' => ['name' => 'Environmental and Industrial Safety'],
                        'es' => ['name' => 'Medio Ambiente y Seguridad Industrial'],
                        'ca' => ['name' => 'Medi Ambient i Seguretat Industrial'],
                    ],
                    'features' => [
                        $this->booleanFeature(1, 'pressure_equipment_over_0_5_bar', 'Pressure equipment above 0.5 bar', 'Equipos a presión de más de 0,5 bar'),
                        $this->booleanFeature(2, 'industrial_boiler', 'Industrial-use boiler', 'Caldera de uso industrial'),
                        $this->booleanFeature(3, 'other_industrial_combustion_equipment', 'Other industrial combustion equipment', 'Algun otro equipo de combustión industrial'),
                        $this->booleanFeature(4, 'atmospheric_emission_points', 'Atmospheric emission points', 'Focos emisores a la atmosfera'),
                        $this->booleanFeature(5, 'solvent_products_or_volatile_processes', 'Activities using solvent-based products or volatile-substance processes', 'Actividades que utilicen productos con disolventes ( pinturas, barnices, tintas, adhesivos, productos de limpieza industrial o desengrase), o procesos donde se evaporen sustancias volátiles'),
                        $this->booleanFeature(6, 'hvac_installations', 'Heating or HVAC installations', 'Instalaciones  de calefacción, climatización'),
                        $this->booleanFeature(7, 'sanitary_water', 'Sanitary water installations', 'Agua sanitaria'),
                        $this->booleanFeature(8, 'water_tanks_or_accumulators', 'Water tanks or storage vessels', 'Depósitos o acumuladores de agua'),
                        $this->booleanFeature(9, 'cooling_towers_or_evaporative_condensers', 'Cooling towers or evaporative condensers', 'Torres de refrigeración o condensadores evaporativos'),
                        $this->booleanFeature(10, 'humidifiers_or_nebulizers', 'Humidifiers, nebulizers, or humidification systems', 'Humidificadores, nebulizadores o sistemas de humidificación'),
                        $this->booleanFeature(11, 'other_aerosol_generating_water_systems', 'Other water systems capable of generating aerosols', 'Otras instalaciones con agua que puedan generar aerosoles'),
                        $this->booleanFeature(12, 'low_voltage_installation', 'Low-voltage installation', 'Instalación baja tensión'),
                        $this->booleanFeature(13, 'high_voltage_installation', 'High-voltage installation', 'Instalación alta tensión'),
                        $this->booleanFeature(14, 'gas_installation', 'Gas installation', 'Instalación de gas'),
                        $this->booleanFeature(15, 'hazardous_chemical_storage', 'Chemical / hazardous products storage', 'Almacén de productos químicos / productos peligrosos'),
                        $this->booleanFeature(16, 'hazardous_products_over_10_tons_year', 'Use or storage above 10 tons/year of hazardous products', 'Utilización o almacenamiento de más de 10T/año de productos peligrosos'),
                        $this->booleanFeature(17, 'underground_hazardous_substance_tank', 'Underground hazardous-substance tank', 'Depósito soterrado de sustancias peligrosas'),
                        $this->booleanFeature(18, 'fuel_tank', 'Diesel, gasoline, or other fuel tank', 'Depósito de gasóleo, gasolina u otro combustible: enterrado, aéreo o interior'),
                        $this->booleanFeature(19, 'non_municipal_water_abstraction', 'Water abstraction from sources other than the municipal network', 'Captaciones de agua  de alguna fuente que no sea la red municipal'),
                        $this->booleanFeature(20, 'refrigeration_installations', 'Refrigeration installations', 'Instalaciones frigoríficas'),
                        $this->booleanFeature(21, 'renewable_energy_installation', 'Renewable energy installation', 'Instalación de energía renovable'),
                        $this->booleanFeature(22, 'dangerous_goods_adr_operations', 'Transports, receives, dispatches, loads, or unloads dangerous goods (ADR)', 'Transporta, recibe, envía, carga o descarga mercancías peligrosas (ADR)'),
                        [
                            'code' => 'hazardous_waste_generation',
                            'data_type' => Feature::DATA_TYPE_SINGLE_CHOICE,
                            'sort_order' => 23,
                            'translations' => [
                                'en' => ['label' => 'Hazardous waste generation'],
                                'es' => ['label' => 'Generación de residuos peligrosos: menos o más de 10T/año'],
                            ],
                            'options' => [
                                $this->option(1, 'none', 'No hazardous waste generation', 'Sin generación de residuos peligrosos'),
                                $this->option(2, 'less_than_10_tons_year', 'Less than 10 tons/year', 'Menos de 10 T/año'),
                                $this->option(3, 'more_than_10_tons_year', 'More than 10 tons/year', 'Más de 10 T/año'),
                            ],
                        ],
                        $this->booleanFeature(24, 'non_hazardous_waste_over_10_tons_year', 'Generation above 10 tons/year of non-hazardous waste', 'Generación de más de 10 T/año de residuos no peligrosos?'),
                        $this->booleanFeature(25, 'non_hazardous_waste_over_1000_tons_year', 'Generation above 1,000 tons/year of non-hazardous waste', 'Generación de más de 1000 T/año de residuos no peligrosos?'),
                        $this->booleanFeature(26, 'packaged_product_producer', 'Producer of packaged products placed on the market', 'Productor de producto (pone productos envasados en el mercado)'),
                        $this->booleanFeature(27, 'electrical_or_electronic_equipment_producer', 'Producer of electrical or electronic equipment', 'Productor de Aparatos eléctricos o electrónicos'),
                        $this->booleanFeature(28, 'batteries_or_accumulators_producer', 'Producer of batteries or accumulators', 'Productor de pilas o baterías'),
                    ],
                ],
                [
                    'code' => 'occupational_health_and_safety',
                    'sort_order' => 2,
                    'translations' => [
                        'en' => ['name' => 'Occupational Health and Safety'],
                        'es' => ['name' => 'Seguridad y salud en el trabajo'],
                        'ca' => ['name' => 'Seguretat i salut en el treball'],
                    ],
                ],
                [
                    'code' => 'food_safety',
                    'sort_order' => 3,
                    'translations' => [
                        'en' => ['name' => 'Food Safety'],
                        'es' => ['name' => 'Seguridad alimentaria'],
                        'ca' => ['name' => 'Seguretat alimentària'],
                    ],
                ],
                [
                    'code' => 'medical_devices',
                    'sort_order' => 4,
                    'translations' => [
                        'en' => ['name' => 'Medical Devices'],
                        'es' => ['name' => 'Productos Sanitarios'],
                        'ca' => ['name' => 'Productes sanitaris'],
                    ],
                ],
                [
                    'code' => 'healthcare',
                    'sort_order' => 5,
                    'translations' => [
                        'en' => ['name' => 'Healthcare'],
                        'es' => ['name' => 'Sanidad'],
                        'ca' => ['name' => 'Sanitat'],
                    ],
                ],
                [
                    'code' => 'sustainability_and_compliance',
                    'sort_order' => 6,
                    'translations' => [
                        'en' => ['name' => 'Sustainability and Compliance'],
                        'es' => ['name' => 'Sostenibilidad y compliance'],
                        'ca' => ['name' => 'Sostenibilitat i compliance'],
                    ],
                ],
                [
                    'code' => 'information_security',
                    'sort_order' => 7,
                    'translations' => [
                        'en' => ['name' => 'Information Security'],
                        'es' => ['name' => 'Seguridad de la información'],
                        'ca' => ['name' => 'Seguretat de la informació'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function booleanFeature(int $sortOrder, string $code, string $labelEn, string $labelEs): array
    {
        return [
            'code' => $code,
            'data_type' => Feature::DATA_TYPE_BOOLEAN,
            'sort_order' => $sortOrder,
            'translations' => [
                'en' => ['label' => $labelEn],
                'es' => ['label' => $labelEs],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function option(int $sortOrder, string $code, string $labelEn, string $labelEs): array
    {
        return [
            'code' => $code,
            'sort_order' => $sortOrder,
            'translations' => [
                'en' => ['label' => $labelEn],
                'es' => ['label' => $labelEs],
            ],
        ];
    }
}
