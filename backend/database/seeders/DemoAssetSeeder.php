<?php

namespace Database\seeders;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Aset demo untuk percobaan manual: 30 aset nyata di organisasi kantor-pusat,
 * dengan campuran kondisi dan status lifecycle supaya filter teruji.
 *
 * Idempoten (kode aset jadi kunci). Jalankan:
 * php artisan db:seed --class=DemoAssetSeeder
 */
class DemoAssetSeeder extends Seeder
{
    /**
     * [nama, kategori, lokasi, kondisi, lifecycle, harga beli]
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int}>
     */
    private const ASSETS = [
        ['Laptop ASUS ProArt 14', 'Elektronik', 'Gudang Utama', Asset::CONDITION_EXCELLENT, Asset::LIFECYCLE_ACTIVE, 21_500_000],
        ['Laptop Lenovo ThinkPad T14', 'Elektronik', 'Ruang Rapat Utama', Asset::CONDITION_GOOD, Asset::LIFECYCLE_ACTIVE, 18_900_000],
        ['MacBook Air M2 13"', 'Elektronik', 'Gudang Utama', Asset::CONDITION_EXCELLENT, Asset::LIFECYCLE_ACTIVE, 19_200_000],
        ['Printer Epson EcoTank L3210', 'Elektronik', 'Ruang Rapat Utama', Asset::CONDITION_GOOD, Asset::LIFECYCLE_ACTIVE, 2_450_000],
        ['Monitor Dell UltraSharp 27"', 'Elektronik', 'Ruang Rapat Utama', Asset::CONDITION_GOOD, Asset::LIFECYCLE_ACTIVE, 4_800_000],
        ['Proyektor Epson EB-X06', 'Kamera & Multimedia', 'Ruang Multimedia', Asset::CONDITION_GOOD, Asset::LIFECYCLE_ACTIVE, 6_100_000],
        ['Proyektor BenQ TH585P', 'Kamera & Multimedia', 'Gudang Utama', Asset::CONDITION_FAIR, Asset::LIFECYCLE_UNDER_MAINTENANCE, 7_300_000],
        ['Kamera Sony Alpha A6400', 'Kamera & Multimedia', 'Studio', Asset::CONDITION_EXCELLENT, Asset::LIFECYCLE_ACTIVE, 14_500_000],
        ['Lensa Sony E PZ 18-105mm', 'Kamera & Multimedia', 'Studio', Asset::CONDITION_EXCELLENT, Asset::LIFECYCLE_ACTIVE, 8_900_000],
        ['Tripod Manfrotto Compact Action', 'Kamera & Multimedia', 'Studio', Asset::CONDITION_GOOD, Asset::LIFECYCLE_ACTIVE, 1_750_000],
        ['Mikrofon Rode Wireless GO II', 'Kamera & Multimedia', 'Studio', Asset::CONDITION_POOR, Asset::LIFECYCLE_ACTIVE, 4_600_000],
        ['Speaker JBL PartyBox 310', 'Kamera & Multimedia', 'Aula', Asset::CONDITION_GOOD, Asset::LIFECYCLE_ACTIVE, 5_900_000],
        ['Meja Rapat Kayu 12 Kursi', 'Furniture', 'Ruang Rapat Utama', Asset::CONDITION_GOOD, Asset::LIFECYCLE_AWAITING_INSPECTION, 12_000_000],
        ['Kursi Ergonomis Ergotex', 'Furniture', 'Ruang Rapat Utama', Asset::CONDITION_FAIR, Asset::LIFECYCLE_ACTIVE, 1_850_000],
        ['Lemari Arsip Baja 4 Rak', 'Furniture', 'Gudang Utama', Asset::CONDITION_GOOD, Asset::LIFECYCLE_ACTIVE, 2_300_000],
        ['Papan Tulis Magnetik 120x240', 'Furniture', 'Ruang Rapat Utama', Asset::CONDITION_POOR, Asset::LIFECYCLE_ACTIVE, 950_000],
        ['Standing AC 2 PK', 'Elektronik', 'Aula', Asset::CONDITION_GOOD, Asset::LIFECYCLE_ACTIVE, 8_400_000],
        ['Kipas Angin Miyako 16"', 'Elektronik', 'Gudang Utama', Asset::CONDITION_FAIR, Asset::LIFECYCLE_ACTIVE, 320_000],
        ['Bor Bosch GSB 16 RE', 'Perkakas', 'Gudang Utama', Asset::CONDITION_GOOD, Asset::LIFECYCLE_ACTIVE, 1_450_000],
        ['Set Kunci Inggris 40 pcs', 'Perkakas', 'Gudang Utama', Asset::CONDITION_GOOD, Asset::LIFECYCLE_ACTIVE, 890_000],
        ['Gerinda Tangan Makita 4"', 'Perkakas', 'Gudang Utama', Asset::CONDITION_FAIR, Asset::LIFECYCLE_ACTIVE, 780_000],
        ['Tangga Aluminium 3 Meter', 'Perkakas', 'Gudang Utama', Asset::CONDITION_FAIR, Asset::LIFECYCLE_ACTIVE, 1_150_000],
        ['Sepeda Gunung Polygon Monza 7', 'Olahraga', 'Gudang Utama', Asset::CONDITION_GOOD, Asset::LIFECYCLE_RETIRED, 3_200_000],
        ['Bola Basket Molten GG7X', 'Olahraga', 'Aula', Asset::CONDITION_GOOD, Asset::LIFECYCLE_ACTIVE, 650_000],
        ['Set Net Badminton Yonex', 'Olahraga', 'Aula', Asset::CONDITION_GOOD, Asset::LIFECYCLE_ACTIVE, 540_000],
        ['Matras Yoga 6 pcs', 'Olahraga', 'Aula', Asset::CONDITION_FAIR, Asset::LIFECYCLE_ACTIVE, 720_000],
        ['Genset Honda EU22i', 'Elektronik', 'Gudang Utama', Asset::CONDITION_EXCELLENT, Asset::LIFECYCLE_ACTIVE, 9_800_000],
        ['Barcode Scanner Zebra DS2208', 'Elektronik', 'Gudang Utama', Asset::CONDITION_GOOD, Asset::LIFECYCLE_ACTIVE, 1_250_000],
        ['Tablet iPad Gen 9 10.2"', 'Elektronik', 'Ruang Multimedia', Asset::CONDITION_EXCELLENT, Asset::LIFECYCLE_ACTIVE, 5_400_000],
        ['Radio Komunikasi Baofeng UV-5R (5 unit)', 'Elektronik', 'Gudang Utama', Asset::CONDITION_UNSERVICEABLE, Asset::LIFECYCLE_UNDER_MAINTENANCE, 1_500_000],
    ];

    public function run(): void
    {
        $organization = Organization::query()->where('slug', 'kantor-pusat')->firstOrFail();

        $categories = [];
        foreach ([
            'Elektronik' => 'Perangkat listrik bergerak dan tetap.',
            'Furniture' => 'Meja, kursi, dan perlengkapan ruangan.',
            'Kamera & Multimedia' => 'Peralatan presentasi dan produksi visual.',
            'Perkakas' => 'Peralatan kerja dan pemeliharaan.',
            'Olahraga' => 'Peralatan kegiatan olahraga.',
        ] as $name => $description) {
            $categories[$name] = Category::firstOrCreate(
                ['organization_id' => $organization->getKey(), 'name' => $name],
                ['description' => $description],
            );
        }

        $locations = [];
        foreach (['Gudang Utama', 'Ruang Rapat Utama', 'Ruang Multimedia', 'Studio', 'Aula'] as $name) {
            $locations[$name] = Location::firstOrCreate(
                ['organization_id' => $organization->getKey(), 'name' => $name],
                ['level' => 1],
            );
        }

        foreach (self::ASSETS as $index => [$name, $category, $location, $condition, $lifecycle, $price]) {
            Asset::firstOrCreate(
                [
                    'organization_id' => $organization->getKey(),
                    'code' => sprintf('AST-%04d', 1001 + $index),
                ],
                [
                    'name' => $name,
                    'description' => "Aset {$name} untuk kebutuhan operasional organisasi.",
                    'category_id' => $categories[$category]->getKey(),
                    'location_id' => $locations[$location]->getKey(),
                    'condition' => $condition,
                    'lifecycle_status' => $lifecycle,
                    'serial_number' => strtoupper(fake()->bothify('??##-####-??')),
                    'purchase_date' => fake()->dateTimeBetween('-3 years', '-6 months')->format('Y-m-d'),
                    'purchase_price' => $price,
                ],
            );
        }

        $this->command?->info(sprintf('Aset demo siap: %d baris di kantor-pusat.', count(self::ASSETS)));
    }
}
