<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AntenneProvinciale;
use App\Models\StructureSanitaire;
use App\Models\Fournisseur;
use App\Models\CommandeFournisseur;
use App\Models\LigneCommandeFournisseur;
use App\Models\ProduitPharmaceutique;
use App\Models\LotProduit;
use App\Models\DemandeApprovisionnement;
use App\Models\LigneDemande;
use App\Models\MissionLogistique;
use App\Models\Livraison;
use App\Models\PreuveLivraison;
use App\Models\RapportMission;
use App\Models\HistoriqueModificationDemande;
use App\Models\UrgenceSanitaire;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Les 9 Antennes Provinciales de la République Gabonaise
        $antennesData = [
            [
                'code_antenne' => 'EST',
                'nom' => 'Estuaire',
                'chef_lieu' => 'Libreville',
                'ville_antenne' => 'Owendo (Hub Central National)',
                'responsable_nom' => 'Dr. Jean-Bernard OBAME / Mme Sylvie NTSAME',
                'contact_urgence' => '+241 011 70 20 00',
                'lead_time_jours' => 1,
                'mode_transport_principal' => 'Routier direct / Hub central',
                'latitude' => 0.3901,
                'longitude' => 9.4544,
                'stock_valeur_xaf' => 485000000,
                'statut_stock' => 'NORMAL',
                'couverture_jours' => 42,
            ],
            [
                'code_antenne' => 'G2-FRV',
                'nom' => 'Haut-Ogooué',
                'chef_lieu' => 'Franceville',
                'ville_antenne' => 'Franceville (Quartier Potos)',
                'responsable_nom' => 'M. Brice ELLA MBA',
                'contact_urgence' => '+241 066 45 88 12',
                'lead_time_jours' => 5,
                'mode_transport_principal' => 'Ferroviaire Setrag + Route',
                'latitude' => -1.6333,
                'longitude' => 13.5833,
                'stock_valeur_xaf' => 92400000,
                'statut_stock' => 'CRITIQUE',
                'couverture_jours' => 18,
            ],
            [
                'code_antenne' => 'G3-LAM',
                'nom' => 'Moyen-Ogooué',
                'chef_lieu' => 'Lambaréné',
                'ville_antenne' => 'Lambaréné (Rive Gauche)',
                'responsable_nom' => 'Dr. Paulin ONDO METOU',
                'contact_urgence' => '+241 062 18 90 44',
                'lead_time_jours' => 2,
                'mode_transport_principal' => 'Routier (RN1 via Kango)',
                'latitude' => -0.7000,
                'longitude' => 10.2333,
                'stock_valeur_xaf' => 64200000,
                'statut_stock' => 'NORMAL',
                'couverture_jours' => 35,
            ],
            [
                'code_antenne' => 'G4-MOU',
                'nom' => 'Ngounié',
                'chef_lieu' => 'Mouila',
                'ville_antenne' => 'Mouila (Centre Ville)',
                'responsable_nom' => 'Dr. Audrey MBOUMBA KOUMBA',
                'contact_urgence' => '+241 077 92 11 05',
                'lead_time_jours' => 4,
                'mode_transport_principal' => 'Routier (RN1 via Mouila)',
                'latitude' => -1.8685,
                'longitude' => 11.0559,
                'stock_valeur_xaf' => 58900000,
                'statut_stock' => 'VIGILANCE',
                'couverture_jours' => 24,
            ],
            [
                'code_antenne' => 'G5-TCH',
                'nom' => 'Nyanga',
                'chef_lieu' => 'Tchibanga',
                'ville_antenne' => 'Tchibanga (Zone Administrative)',
                'responsable_nom' => 'M. Dieudonné MOUSSAVOU',
                'contact_urgence' => '+241 066 33 21 89',
                'lead_time_jours' => 6,
                'mode_transport_principal' => 'Routier lourd (Axe Mouila-Tchibanga)',
                'latitude' => -2.8500,
                'longitude' => 11.0167,
                'stock_valeur_xaf' => 44100000,
                'statut_stock' => 'VIGILANCE',
                'couverture_jours' => 21,
            ],
            [
                'code_antenne' => 'G6-MAK',
                'nom' => 'Ogooué-Ivindo',
                'chef_lieu' => 'Makokou',
                'ville_antenne' => 'Makokou (Près CHR)',
                'responsable_nom' => 'Dr. Serge MEZUI M\'ELLA',
                'contact_urgence' => '+241 074 81 09 33',
                'lead_time_jours' => 7,
                'mode_transport_principal' => 'Routier (Axe Lalara - Koumameyong)',
                'latitude' => 0.5667,
                'longitude' => 12.8667,
                'stock_valeur_xaf' => 38200000,
                'statut_stock' => 'CRITIQUE',
                'couverture_jours' => 14,
            ],
            [
                'code_antenne' => 'G7-KLM',
                'nom' => 'Ogooué-Lolo',
                'chef_lieu' => 'Koula-Moutou',
                'ville_antenne' => 'Koula-Moutou (Gare Lastourville)',
                'responsable_nom' => 'Mme Clarisse MBOUMBA BIVEGHE',
                'contact_urgence' => '+241 065 47 18 20',
                'lead_time_jours' => 5,
                'mode_transport_principal' => 'Setrag (Lastourville) + Route',
                'latitude' => -1.1333,
                'longitude' => 12.4833,
                'stock_valeur_xaf' => 46800000,
                'statut_stock' => 'NORMAL',
                'couverture_jours' => 31,
            ],
            [
                'code_antenne' => 'G8-POG',
                'nom' => 'Ogooué-Maritime',
                'chef_lieu' => 'Port-Gentil',
                'ville_antenne' => 'Port-Gentil (Grand Village)',
                'responsable_nom' => 'M. Marc ANGUILET',
                'contact_urgence' => '+241 077 14 55 90',
                'lead_time_jours' => 3,
                'mode_transport_principal' => 'Maritime (Barge Cargo depuis Owendo)',
                'latitude' => -0.7194,
                'longitude' => 8.7814,
                'stock_valeur_xaf' => 89700000,
                'statut_stock' => 'NORMAL',
                'couverture_jours' => 38,
            ],
            [
                'code_antenne' => 'G9-OYE',
                'nom' => 'Woleu-Ntem',
                'chef_lieu' => 'Oyem',
                'ville_antenne' => 'Oyem (Axe Bitam)',
                'responsable_nom' => 'Dr. Emmanuel NDONG ASSOUMOU',
                'contact_urgence' => '+241 066 89 74 10',
                'lead_time_jours' => 4,
                'mode_transport_principal' => 'Routier (RN2 via Ndjolé)',
                'latitude' => 1.6000,
                'longitude' => 11.5833,
                'stock_valeur_xaf' => 76500000,
                'statut_stock' => 'VIGILANCE',
                'couverture_jours' => 26,
            ],
        ];

        foreach ($antennesData as $data) {
            AntenneProvinciale::updateOrCreate(['code_antenne' => $data['code_antenne']], $data);
        }

        $hubOwendo = AntenneProvinciale::where('code_antenne', 'EST')->first();
        $antenneFrv = AntenneProvinciale::where('code_antenne', 'G2-FRV')->first();
        $antenneLam = AntenneProvinciale::where('code_antenne', 'G3-LAM')->first();
        $antenneMou = AntenneProvinciale::where('code_antenne', 'G4-MOU')->first();
        $antenneTch = AntenneProvinciale::where('code_antenne', 'G5-TCH')->first();
        $antenneMak = AntenneProvinciale::where('code_antenne', 'G6-MAK')->first();
        $antenneKlm = AntenneProvinciale::where('code_antenne', 'G7-KLM')->first();
        $antennePog = AntenneProvinciale::where('code_antenne', 'G8-POG')->first();
        $antenneOye = AntenneProvinciale::where('code_antenne', 'G9-OYE')->first();

        // 2. Structures Sanitaires Nationales
        $structuresData = [
            [
                'code_structure' => 'CHR-EST-01',
                'nom' => 'Centre Hospitalier Universitaire de Libreville (CHUL)',
                'type_structure' => 'HOPITAL_REGIONAL',
                'antenne_id' => $hubOwendo->id,
                'province_code' => 'G1',
                'departement' => 'Komo-Mondah',
                'ville' => 'Libreville',
                'responsable_nom' => 'Prof. Albert MOUNGOUNGA',
                'contact_urgence' => '+241 011 76 00 00',
                'email' => 'direction@chul.ga',
            ],
            [
                'code_structure' => 'CHR-FRV-01',
                'nom' => 'Centre Hospitalier Régional Amissa Bongo (CHR Franceville)',
                'type_structure' => 'HOPITAL_REGIONAL',
                'antenne_id' => $antenneFrv->id,
                'province_code' => 'G2',
                'departement' => 'Passa',
                'ville' => 'Franceville',
                'responsable_nom' => 'Dr. Paul ANDJEMBE',
                'contact_urgence' => '+241 066 22 11 00',
                'email' => 'chr.franceville@sante.gouv.ga',
            ],
            [
                'code_structure' => 'HAS-LAM-02',
                'nom' => 'Hôpital Albert Schweitzer de Lambaréné',
                'type_structure' => 'HOPITAL_REGIONAL',
                'antenne_id' => $antenneLam->id,
                'province_code' => 'G3',
                'departement' => 'Ogooué et des Lacs',
                'ville' => 'Lambaréné',
                'responsable_nom' => 'Dr. Antoine BOUDZANGA',
                'contact_urgence' => '+241 062 18 22 33',
                'email' => 'contact@schweitzer-lambarene.org',
            ],
            [
                'code_structure' => 'CHR-POG-03',
                'nom' => 'Centre Hospitalier Régional de Port-Gentil (CHR Ntchengué)',
                'type_structure' => 'HOPITAL_REGIONAL',
                'antenne_id' => $antennePog->id,
                'province_code' => 'G8',
                'departement' => 'Bendjé',
                'ville' => 'Port-Gentil',
                'responsable_nom' => 'Dr. Roger KOMBILA',
                'contact_urgence' => '+241 077 14 00 11',
                'email' => 'chr.portgentil@sante.gouv.ga',
            ],
            [
                'code_structure' => 'PHARM-POG-04',
                'nom' => 'Pharmacie Centrale du Littoral Port-Gentil',
                'type_structure' => 'PHARMACIE_OFFICINE',
                'antenne_id' => $antennePog->id,
                'province_code' => 'G8',
                'departement' => 'Bendjé',
                'ville' => 'Port-Gentil',
                'responsable_nom' => 'Dr. Marie-Claire OGANDAGA',
                'contact_urgence' => '+241 065 99 88 77',
                'email' => 'pharmacie.littoral@gmail.com',
            ],
            [
                'code_structure' => 'CHR-MAK-05',
                'nom' => 'Centre Hospitalier Régional de Makokou',
                'type_structure' => 'HOPITAL_REGIONAL',
                'antenne_id' => $antenneMak->id,
                'province_code' => 'G6',
                'departement' => 'Ivindo',
                'ville' => 'Makokou',
                'responsable_nom' => 'Dr. Serge MEZUI M\'ELLA',
                'contact_urgence' => '+241 074 81 09 33',
                'email' => 'chr.makokou@sante.gouv.ga',
            ],
        ];

        foreach ($structuresData as $st) {
            StructureSanitaire::updateOrCreate(['code_structure' => $st['code_structure']], $st);
        }

        $chrFranceville = StructureSanitaire::where('code_structure', 'CHR-FRV-01')->first();
        $chrPortGentil = StructureSanitaire::where('code_structure', 'CHR-POG-03')->first();
        $pharmPortGentil = StructureSanitaire::where('code_structure', 'PHARM-POG-04')->first();

        // 3. Utilisateurs métier OPN (21 profils dont Admin Métier + 2 Collaborateurs Appro)
        $usersData = [
            [
                'name' => 'Ing. Patrick ONDO MVE',
                'email' => 'superadmin@opn.ga',
                'password' => Hash::make('OPN2026@SuperAdmin'),
                'role' => 'Super Administrateur National',
                'code_role' => 'SUPER_ADMIN',
                'entite' => 'Gouvernance Centrale OPN - Libreville',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-ADM-001',
                'telephone' => '+241 011 70 00 01',
                'is_2fa_enabled' => false,
                'etat' => 'actif',
            ],
            [
                'name' => 'Admin Métier',
                'email' => 'admin.metier@opn.ga',
                'password' => Hash::make('OPN2026@Metier'),
                'role' => 'Admin métier',
                'code_role' => 'ADMIN_METIER',
                'entite' => 'Paramétrage métier OPN - Libreville',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-MET-001',
                'telephone' => '+241 011 70 00 02',
                'is_2fa_enabled' => false,
                'etat' => 'actif',
            ],
            [
                'name' => 'Ing. Kevin MAVOUNGOU BIYOGHE',
                'email' => 'admin.it@opn.ga',
                'password' => Hash::make('OPN2026@AdminIT'),
                'role' => 'Administrateur IT & Cyber-Sécurité',
                'code_role' => 'ADMIN_IT',
                'entite' => 'Direction des Systèmes d\'Information & ANINF - Libreville',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-IT-002',
                'telephone' => '+241 074 00 11 22',
                'is_2fa_enabled' => true,
            ],
            [
                'name' => 'Dr. Jean-Bernard OBAME NDONG',
                'email' => 'jb.obame@opn.ga',
                'password' => Hash::make('OPN2026@Libreville'),
                'role' => 'Directeur Général',
                'code_role' => 'DG',
                'entite' => 'Direction Générale OPN - Libreville',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-DIR-001',
                'telephone' => '+241 011 70 20 01',
                'is_2fa_enabled' => true,
            ],
            [
                'name' => 'M. Alain KOUMBA BIVEGHE',
                'email' => 'dir.operations@opn.ga',
                'password' => Hash::make('OPN2026@Direction'),
                'role' => 'Directeur des Opérations & Pilotage',
                'code_role' => 'DIR',
                'entite' => 'Direction des Opérations - Libreville',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-DIR-003',
                'telephone' => '+241 077 44 55 66',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'Mme Chantal NZENGUE OBIANG',
                'email' => 'daf@opn.ga',
                'password' => Hash::make('OPN2026@Finance'),
                'role' => 'Directrice Administrative & Financière',
                'code_role' => 'DAF',
                'entite' => 'Direction Administrative et Financière - Libreville',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-FIN-001',
                'telephone' => '+241 011 70 20 05',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'Mme Sylvie NTSAME ONDO',
                'email' => 's.ntsame@opn.ga',
                'password' => Hash::make('OPN2026@Owendo'),
                'role' => 'Directrice des Approvisionnements Nationaux',
                'code_role' => 'APPRO_NATIONAL',
                'entite' => 'Direction de la Logistique & Approvisionnement - Hub Owendo',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-APP-001',
                'telephone' => '+241 074 12 34 56',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'Mme Grâce NDZIÉ OBAME',
                'email' => 'g.ndzie@opn.ga',
                'password' => Hash::make('OPN2026@CollabAppro'),
                'role' => 'Collaboratrice Approvisionnements',
                'code_role' => 'COLLABORATEUR_APPRO',
                'entite' => 'Direction de la Logistique & Approvisionnement - Hub Owendo',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-APP-002',
                'telephone' => '+241 074 12 34 70',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'M. Jean OBIANG ESSONO',
                'email' => 'j.obiang@opn.ga',
                'password' => Hash::make('OPN2026@CollabAppro2'),
                'role' => 'Collaborateur Approvisionnements',
                'code_role' => 'COLLABORATEUR_APPRO',
                'entite' => 'Direction de la Logistique & Approvisionnement - Hub Owendo',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-APP-003',
                'telephone' => '+241 074 12 34 71',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'M. Christian BIKORO MVE',
                'email' => 'achats@opn.ga',
                'password' => Hash::make('OPN2026@Achats'),
                'role' => 'Responsable Achats & Marchés Fournisseurs',
                'code_role' => 'ACHATS',
                'entite' => 'Service Achats & Relations Fabricants - Libreville',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-ACH-001',
                'telephone' => '+241 066 11 22 33',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'Dr. François MBA ALLOGO',
                'email' => 'pharmacien.national@opn.ga',
                'password' => Hash::make('OPN2026@PharmacieNat'),
                'role' => 'Pharmacien Chef National',
                'code_role' => 'PHARMACIEN_CHEF_NATIONAL',
                'entite' => 'Direction Pharmaceutique Nationale - Hub Owendo',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-PHAR-001',
                'telephone' => '+241 077 90 80 70',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'Dr. Estelle MANFOUMBI',
                'email' => 'pharmacie.frv@opn.ga',
                'password' => Hash::make('OPN2026@PharmacieFrv'),
                'role' => 'Pharmacien Chef d\'Antenne Haut-Ogooué',
                'code_role' => 'PHARMACIEN_CHEF_ANTENNE',
                'entite' => 'Antenne Provinciale Haut-Ogooué - Franceville',
                'province_id' => $antenneFrv->id,
                'matricule_opn' => 'OPN-PHAR-G2',
                'telephone' => '+241 066 77 88 99',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'M. Marc ANGUILET',
                'email' => 'chef.antenne.pog@opn.ga',
                'password' => Hash::make('OPN2026@PortGentil'),
                'role' => 'Chef d\'Antenne Provinciale Ogooué-Maritime',
                'code_role' => 'CHEF_ANTENNE_PROVINCIALE',
                'entite' => 'Antenne Provinciale Ogooué-Maritime - Port-Gentil',
                'province_id' => $antennePog->id,
                'matricule_opn' => 'OPN-ANT-G8',
                'telephone' => '+241 077 14 55 90',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'Dr. Audrey MBOUMBA KOUMBA',
                'email' => 'urgences@opn.ga',
                'password' => Hash::make('OPN2026@Urgences'),
                'role' => 'Coordonnatrice Nationale des Urgences Sanitaires',
                'code_role' => 'COORDINATEUR_URGENCES',
                'entite' => 'Centre Opérationnel des Urgences (COD Libreville)',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-URG-001',
                'telephone' => '+241 077 92 11 05',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'M. Rodrigue MAVOUNGOU',
                'email' => 'logistique@opn.ga',
                'password' => Hash::make('OPN2026@Logistique'),
                'role' => 'Gestionnaire Logistique & Flotte Convois',
                'code_role' => 'GESTIONNAIRE_LOGISTIQUE',
                'entite' => 'Direction des Transports & Flotte - Hub Owendo',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-LOG-001',
                'telephone' => '+241 062 99 44 11',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'Capitaine Guy-Roger NGUEMA',
                'email' => 'securite.convois@opn.ga',
                'password' => Hash::make('OPN2026@Securite'),
                'role' => 'Chef de Convoi Sécurisé & Sûreté',
                'code_role' => 'CHEF_CONVOI_SECURITE',
                'entite' => 'Escorte Sécurisée & Protection Convois RN1/RN2',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-SEC-001',
                'telephone' => '+241 065 12 34 56',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'M. Jean-Paul MVE',
                'email' => 'convoyeur@opn.ga',
                'password' => Hash::make('OPN2026@Convoyeur'),
                'role' => 'Convoyeur Opérationnel de Fret Pharmaceutique',
                'code_role' => 'CONVOYEUR',
                'entite' => 'Équipe Convois Mobiles Owendo / Setrag',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-CVY-001',
                'telephone' => '+241 074 55 66 77',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'M. Paul MEBIAME',
                'email' => 'commercial.oyem@opn.ga',
                'password' => Hash::make('OPN2026@Vendeur'),
                'role' => 'Vendeur / Commercial Terrain',
                'code_role' => 'VENDEUR',
                'entite' => 'Direction Commerciale — Antenne Woleu-Ntem (Oyem)',
                'province_id' => $antenneOye->id,
                'matricule_opn' => 'OPN-VEN-001',
                'telephone' => '+241 077 45 12 88',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'Dr. Marie-Claire OGANDAGA',
                'email' => 'pharmacien.pog@opn.ga',
                'password' => Hash::make('OPN2026@PharmaciePog'),
                'role' => 'Demandeur - Pharmacien d\'Officine Port-Gentil',
                'code_role' => 'DEMANDEUR_PHARMACIEN',
                'entite' => 'Pharmacie Centrale du Littoral - Port-Gentil',
                'province_id' => $antennePog->id,
                'structure_id' => $pharmPortGentil ? $pharmPortGentil->id : null,
                'matricule_opn' => 'OPN-DEM-PH01',
                'telephone' => '+241 065 99 88 77',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'Dr. Roger KOMBILA',
                'email' => 'structure.pog@opn.ga',
                'password' => Hash::make('OPN2026@ChrPog'),
                'role' => 'Demandeur - Direction CHR Port-Gentil',
                'code_role' => 'DEMANDEUR_STRUCTURE',
                'entite' => 'Centre Hospitalier Régional de Port-Gentil',
                'province_id' => $antennePog->id,
                'structure_id' => $chrPortGentil ? $chrPortGentil->id : null,
                'matricule_opn' => 'OPN-DEM-ST01',
                'telephone' => '+241 077 14 00 11',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'Mme Léa BIGNHOUMBA',
                'email' => 'communication@opn.ga',
                'password' => Hash::make('OPN2026@Com'),
                'role' => 'Responsable Communication & Relations Extérieures',
                'code_role' => 'COMMUNICATION',
                'entite' => 'Direction de la Communication - Libreville',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-COM-001',
                'telephone' => '+241 077 88 12 90',
                'is_2fa_enabled' => false,
            ],
            [
                'name' => 'Inspecteur Martial BOUNDA',
                'email' => 'audit.interne@opn.ga',
                'password' => Hash::make('OPN2026@Audit'),
                'role' => 'Auditeur Interne & Conformité Réglementaire',
                'code_role' => 'AUDITEUR_INTERNE',
                'entite' => 'Inspection Générale & Audit interne - Libreville',
                'province_id' => $hubOwendo->id,
                'matricule_opn' => 'OPN-AUD-001',
                'telephone' => '+241 011 70 20 10',
                'is_2fa_enabled' => false,
            ],
        ];

        foreach ($usersData as $u) {
            $user = User::firstOrNew(['email' => $u['email']]);
            $user->forceFill($u)->save();
        }

        $convoyeurUser = User::where('email', 'convoyeur@opn.ga')->first();

        // 4. Fournisseurs Internationaux
        $fournisseursData = [
            [
                'code_fournisseur' => 'FOURN-SAN-01',
                'nom' => 'Sanofi Winthrop Industrie',
                'pays' => 'France',
                'type_fournisseur' => 'LABORATOIRE_FABRICANT',
                'certifications' => 'BPF / OMS / GMP / ANSM',
                'delai_moyen_livraison_jours' => 30,
                'contact_nom' => 'M. Philippe DUVAL',
                'contact_email' => 'philippe.duval@sanofi.com',
                'contact_telephone' => '+33 1 53 77 40 00',
            ],
            [
                'code_fournisseur' => 'FOURN-ZEN-02',
                'nom' => 'Zenufa Laboratories',
                'pays' => 'Inde / RDC',
                'type_fournisseur' => 'LABORATOIRE_FABRICANT',
                'certifications' => 'GMP / BPF / OMS',
                'delai_moyen_livraison_jours' => 25,
                'contact_nom' => 'Dr. Rajesh PATEL',
                'contact_email' => 'sales@zenufa.com',
                'contact_telephone' => '+91 22 66 77 88',
            ],
            [
                'code_fournisseur' => 'FOURN-FRE-03',
                'nom' => 'Fresenius Kabi Africa',
                'pays' => 'Allemagne / Afrique du Sud',
                'type_fournisseur' => 'LABORATOIRE_FABRICANT',
                'certifications' => 'BPF Solutés & Perfusion',
                'delai_moyen_livraison_jours' => 20,
                'contact_nom' => 'Mme Helga MULLER',
                'contact_email' => 'order@fresenius-kabi.com',
                'contact_telephone' => '+49 6172 686 0',
            ],
            [
                'code_fournisseur' => 'FOURN-INO-04',
                'nom' => 'Inosan Biopharma',
                'pays' => 'Espagne / Mexique',
                'type_fournisseur' => 'LABORATOIRE_FABRICANT',
                'certifications' => 'Sérums Antivenimeux Homologués OMS',
                'delai_moyen_livraison_jours' => 15,
                'contact_nom' => 'Dr. Carlos MENDEZ',
                'contact_email' => 'urgence@inosanbiopharma.com',
                'contact_telephone' => '+34 91 55 44 33',
            ],
        ];

        foreach ($fournisseursData as $f) {
            Fournisseur::updateOrCreate(['code_fournisseur' => $f['code_fournisseur']], $f);
        }

        $sanofi = Fournisseur::where('code_fournisseur', 'FOURN-SAN-01')->first();

        // 5. Catalogue National des Médicaments (DCI)
        $p1 = ProduitPharmaceutique::updateOrCreate(
            ['code_produit' => 'OPN-PAL-001'],
            [
                'designation' => 'Artéméther + Luméfantrine 20/120mg',
                'dci' => 'Artéméther / Luméfantrine',
                'forme' => 'Comprimé dispersible',
                'dosage' => '20mg / 120mg',
                'conditionnement' => 'Boîte de 24 comprimés',
                'categorie' => 'Antipaludique (ACT)',
                'laboratoire' => 'Sanofi / Zenufa Pharma',
                'unite_gestion' => 'Boîte',
                'prix_unitaire_xaf' => 1850,
                'prix_public_plafonne_xaf' => 2200,
                'stock_national' => 48500,
                'seuil_min_national' => 15000,
                'seuil_critique_national' => 8000,
                'cmm_nationale' => 12000,
                'delai_moyen_appro_jours' => 30,
                'statut' => 'DISPONIBLE',
            ]
        );

        $p2 = ProduitPharmaceutique::updateOrCreate(
            ['code_produit' => 'OPN-ANT-003'],
            [
                'designation' => 'Paracétamol Injectable 10mg/ml 100ml',
                'dci' => 'Paracétamol',
                'forme' => 'Solution injectable pour perfusion',
                'dosage' => '1g / 100ml',
                'conditionnement' => 'Flacon de 100ml',
                'categorie' => 'Analgésique & Antipyrétique',
                'laboratoire' => 'Fresenius Kabi / B. Braun',
                'unite_gestion' => 'Flacon',
                'prix_unitaire_xaf' => 450,
                'prix_public_plafonne_xaf' => 650,
                'stock_national' => 58000,
                'seuil_min_national' => 20000,
                'seuil_critique_national' => 9000,
                'cmm_nationale' => 18000,
                'delai_moyen_appro_jours' => 20,
                'statut' => 'DISPONIBLE',
            ]
        );

        $p3 = ProduitPharmaceutique::updateOrCreate(
            ['code_produit' => 'OPN-URG-004'],
            [
                'designation' => 'Sérum Polyvalent Antivenimeux (Afrique Sub-Saharienne)',
                'dci' => 'Immunoglobuline équine antivenin',
                'forme' => 'Flacon injectable',
                'dosage' => '10ml IV',
                'conditionnement' => 'Boîte unitaire',
                'categorie' => 'Urgence Vitale & Sérum',
                'laboratoire' => 'Inosan Biopharma',
                'unite_gestion' => 'Flacon',
                'prix_unitaire_xaf' => 48000,
                'prix_public_plafonne_xaf' => 55000,
                'stock_national' => 420,
                'seuil_min_national' => 250,
                'seuil_critique_national' => 100,
                'cmm_nationale' => 75,
                'delai_moyen_appro_jours' => 45,
                'statut' => 'STOCK_FAIBLE',
            ]
        );

        // 6. Lots FEFO
        LotProduit::updateOrCreate(
            ['numero_lot' => 'LOT-2025-ACT-884'],
            [
                'produit_id' => $p1->id,
                'antenne_id' => $hubOwendo->id,
                'quantite_disponible' => 14000,
                'date_fabrication' => '2024-06-15',
                'date_peremption' => '2026-11-30',
                'laboratoire_fabricant' => 'Sanofi',
                'statut_fefo' => 'PRIORITAIRE',
                'emplacement_rack' => 'Rack A-04',
            ]
        );

        LotProduit::updateOrCreate(
            ['numero_lot' => 'LOT-2024-PAR-402'],
            [
                'produit_id' => $p2->id,
                'antenne_id' => $antenneFrv->id,
                'quantite_disponible' => 900,
                'date_fabrication' => '2024-02-15',
                'date_peremption' => '2026-10-15',
                'laboratoire_fabricant' => 'Fresenius Kabi',
                'statut_fefo' => 'PRIORITAIRE',
                'emplacement_rack' => 'Armoire Urgence FRV',
            ]
        );

        // 7. Commande d'Achats Internationale (Achats / Labo)
        if ($sanofi) {
            $cmdAch = CommandeFournisseur::updateOrCreate(
                ['numero_commande' => 'CMD-ACHAT-2026-001'],
                [
                    'fournisseur_id' => $sanofi->id,
                    'montant_total_xaf' => 85000000,
                    'statut' => 'VALIDE_SIGNE',
                    'date_commande' => now()->subDays(5),
                    'date_livraison_prevue' => now()->addDays(25),
                    'conditions_paiement' => 'Lettre de Crédit Irrévocable (LC)',
                    'visa_dir_nom' => 'M. Alain KOUMBA BIVEGHE',
                    'visa_dir_at' => now()->subDays(4),
                    'visa_appro_nom' => 'Mme Sylvie NTSAME ONDO',
                    'visa_appro_at' => now()->subDays(3),
                    'visa_daf_nom' => 'Mme Chantal NZENGUE OBIANG',
                    'visa_daf_at' => now()->subDays(2),
                    'visa_dg_nom' => 'Dr. Jean-Bernard OBAME NDONG',
                    'visa_dg_at' => now()->subDays(1),
                    'signature_hash' => 'OPN-SIG-DG-88402919941',
                    'qr_token' => 'QR-CERT-77A44109',
                    'notes' => 'Achat annuel massif ACT et solutés',
                ]
            );

            LigneCommandeFournisseur::updateOrCreate(
                ['commande_fournisseur_id' => $cmdAch->id, 'produit_id' => $p1->id],
                [
                    'quantite_commandee' => 45000,
                    'prix_unitaire_xaf' => 1850,
                    'total_ligne_xaf' => 83250000,
                ]
            );
        }

        // 8. Demandes d'Approvisionnement avec Validation Partielle & Signature
        $demandePog = DemandeApprovisionnement::updateOrCreate(
            ['numero_demande' => 'OPN-DEM-POG-2026-012'],
            [
                'antenne_id' => $antennePog->id,
                'structure_id' => $chrPortGentil ? $chrPortGentil->id : null,
                'demandeur_nom' => 'Dr. Roger KOMBILA (CHR Port-Gentil)',
                'statut' => 'SOUMISE_ANTENNE',
                'etape_index' => 2,
                'mode_transport' => 'Maritime (Barge Cargo)',
                'numero_bordereau' => 'BL-MAR-2026-102',
                'transporteur' => 'Barge Cargo OPN Maritime',
                'chauffeur_ou_agent' => 'M. Jean-Paul MVE',
                'montant_total_xaf' => 148000,
                'quantite_totale_demandee' => 100,
                'quantite_totale_validee' => 80,
                'quantite_totale_reliquat' => 20,
                'jeton_signature_officiel' => 'OPN-VAL-POG-0088412998',
                'qr_token_verification' => 'QR-VAL-88220011',
                'signataire_nom' => 'M. Marc ANGUILET',
                'signataire_role' => 'Chef d\'Antenne Provinciale Ogooué-Maritime',
                'date_signature_officielle' => now()->subHours(3),
                'motif' => 'Dotation mensuelle urgences chirurgicales et pédiatrie',
                'historique_workflow' => [
                    ['etape' => 'Création demande', 'date' => now()->subHours(6)->format('d/m/Y H:i'), 'auteur' => 'Dr. Roger KOMBILA', 'valide' => true],
                    ['etape' => 'Validation Partielle Chef Antenne (80/100)', 'date' => now()->subHours(3)->format('d/m/Y H:i'), 'auteur' => 'M. Marc ANGUILET', 'valide' => true],
                ]
            ]
        );

        $l1 = LigneDemande::updateOrCreate(
            ['demande_id' => $demandePog->id, 'produit_id' => $p1->id],
            [
                'quantite_demandee' => 100,
                'quantite_disponible' => 80,
                'quantite_validee' => 80,
                'quantite_refusee' => 0,
                'quantite_reliquat' => 20,
                'motif_reliquat' => 'Disponibilité actuelle limitée en stock central',
                'commentaire_ligne' => 'Reliquat de 20 unités programmé sur le prochain convoi maritime',
                'prix_unitaire_xaf' => 1850,
                'total_ligne_xaf' => 148000,
            ]
        );

        HistoriqueModificationDemande::updateOrCreate(
            ['demande_id' => $demandePog->id, 'champ_modifie' => 'quantite_validee'],
            [
                'ligne_id' => $l1->id,
                'auteur_nom' => 'M. Marc ANGUILET',
                'role_auteur' => 'Chef d\'Antenne Provinciale',
                'ancienne_valeur' => '100 unités',
                'nouvelle_valeur' => '80 unités (Reliquat: 20)',
                'motif' => 'Disponibilité actuelle limitée en stock local',
                'commentaire' => 'Validation partielle effectuée conformément aux dotations d\'arbitrage.',
            ]
        );

        // 9. Mission Logistique & Convoi
        $mission = MissionLogistique::updateOrCreate(
            ['code_mission' => 'MIS-2026-088'],
            [
                'ordre_mission_ref' => 'ODM-OPN-2026-88',
                'convoi_nom' => 'Convoi Express Maritime Owendo ➔ Port-Gentil',
                'type_transport' => 'MARITIME_BARGE',
                'vehicule_immat' => 'BARGE-OPN-02',
                'vehicule_type' => 'Barge Cargo Frigorifique Fluvio-Maritime',
                'chauffeur_ou_agent' => 'M. Jean-Paul MVE',
                'chef_convoi_nom' => 'Capitaine Guy-Roger NGUEMA',
                'convoyeur_id' => $convoyeurUser ? $convoyeurUser->id : null,
                'antenne_depart_id' => $hubOwendo->id,
                'antenne_destination_id' => $antennePog->id,
                'statut' => 'EN_ROUTE',
                'date_depart' => now()->subHours(4),
                'date_arrivee_estimee' => now()->addHours(2),
                'temperature_actuelle' => 4.1,
                'temperature_conforme' => true,
                'kilometrage_depart' => 0,
                'consignes_securite' => 'Maintien strict de la chaîne du froid 2°C–8°C.',
            ]
        );

        $livraisonPog = Livraison::updateOrCreate(
            ['code_livraison' => 'LIV-POG-2026-012'],
            [
                'mission_id' => $mission->id,
                'demande_id' => $demandePog->id,
                'structure_id' => $chrPortGentil ? $chrPortGentil->id : null,
                'statut' => 'EN_TRANSIT',
                'qr_token' => 'QR-LIV-POG-884920199',
                'token_expires_at' => now()->addDays(5),
                'is_qr_scanned' => false,
                'is_closed' => false,
            ]
        );

        // 10. Urgence Sanitaire COD Libreville
        UrgenceSanitaire::updateOrCreate(
            ['code_incident' => 'URG-2026-088'],
            [
                'antenne_id' => $antenneMak->id,
                'titre' => 'Rupture Imminente Sérum Antivenimeux Polyvalent',
                'lieu_precis' => 'CHR de Makokou (Koumameyong)',
                'type_urgence' => 'URGENCE_PHARMACEUTIQUE',
                'gravite' => 'CRITIQUE',
                'statut' => 'EN_COURS',
                'declarant_nom' => 'Dr. Serge MEZUI M\'ELLA',
                'description' => 'Stock local réduit à 4 flacons suite à 3 cas d\'envenimations graves.',
                'equipe_affectee' => 'Convoi Rapide 4x4 OPN Owendo - M. Rodrigue MAVOUNGOU',
                'action_engagee' => 'Convoi spécial déployé avec glacières autonomes à température contrôlée.',
                'temps_prise_en_charge_minutes' => 18,
                'latitude' => 0.5667,
                'longitude' => 12.8667,
            ]
        );

        // 11. Audit Logs
        AuditLog::create([
            'auteur_nom' => 'Dr. Jean-Bernard OBAME NDONG',
            'role' => 'Directeur Général',
            'action' => 'SIGNATURE_CONVENTION_ACHATS',
            'details' => 'Signature électronique bon de commande international Sanofi CMD-ACHAT-2026-001 (85 000 000 FCFA).',
            'ip_address' => '41.243.2.10 (Libreville)',
        ]);

        AuditLog::create([
            'auteur_nom' => 'M. Marc ANGUILET',
            'role' => 'Chef d\'Antenne Provinciale',
            'action' => 'VALIDATION_PARTIELLE_DEMANDE',
            'details' => 'Validation partielle de 80/100 unités pour CHR Port-Gentil (Demande OPN-DEM-POG-2026-012).',
            'ip_address' => '41.243.8.44 (Port-Gentil)',
        ]);

        // 12. Catalogue national complet (DCI) — synchro depuis database/data/catalogue_national_opn.json
        $this->call(CatalogNationalSeeder::class);

        // 13. OPN Connect — conversations privées uniquement (pas de canal public)
        $this->call(ConnectSeeder::class);
        $this->call(ProfilHabilitationSeeder::class);

        // 14. Chefs d'antenne + demandeurs pour les 9 provinces
        $this->call(ProvincesActeursSeeder::class);
        $this->call(ProvincesDepuisAntennesSeeder::class);

        // 15. Contenu du site public (actualités, marchés, textes institutionnels)
        $this->call(SitePublicSeeder::class);

        // 16. Prospects commerciaux (seed SICL / vendeur)
        $this->call(CommercialProspectSeeder::class);
    }
}
