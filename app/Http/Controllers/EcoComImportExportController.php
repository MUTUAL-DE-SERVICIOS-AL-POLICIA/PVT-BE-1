<?php

namespace Muserpol\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Muserpol\Imports\EcoComImportSenasir;
use Muserpol\Models\EconomicComplement\EconomicComplement;
use Muserpol\Imports\EcoComImportAPS;
use Muserpol\Helpers\Util;
use Muserpol\Imports\EcoComImportPagoFuturo;
use Muserpol\Models\Affiliate;

class EcoComImportExportController extends Controller
{
    public function importSenasir(Request $request)
    {
        if ($request->refresh != 'true') {
            $uploadedFile = $request->file('image');
            $filename = 'senasir.' . $uploadedFile->getClientOriginalExtension();
            Storage::disk('local')->putFileAs(
                'senasir/' . now()->year,
                $uploadedFile,
                $filename
            );
        }
        Excel::import(new EcoComImportSenasir, 'senasir/' . now()->year . '/senasir.xlsx');
        $no_import = EconomicComplement::with('eco_com_beneficiary')->select('economic_complements.*')
            ->leftJoin('affiliates', 'economic_complements.affiliate_id', '=', 'affiliates.id')
            ->where('eco_com_procedure_id', 14)
            ->where('rent_type', '<>', 'Automatico')
            ->where('affiliates.pension_entity_id', 5)
            ->get();
        return array_merge(session()->get('senasir_data'), ['not_found' => $no_import]);

        // return session()->get('senasir_data');
    }
    public function importAPS(Request $request)
    {
        logger($request->all());
        $success = 0;
        $not_found = collect([]);
        $not_found_db = collect([]);
        $not_has_eco_com = collect([]);
        $sw_refresh = false;
        // $sw_override = false;
        if ($request->refresh == 'true') {
            $sw_refresh = true;
        }
        // if ($request->override == 'true') {
        //     $sw_override = true;
        // }
        switch ($request->type) {
            case 'vejez':
                if (!$sw_refresh) {
                    $uploadedFile = $request->file('vejez');
                    $filename = 'aps-vejez.' . $uploadedFile->getClientOriginalExtension();
                    Storage::disk('local')->putFileAs(
                        'aps/' . now()->year,
                        $uploadedFile,
                        $filename
                    );
                };
                Excel::import(new EcoComImportAPS, 'aps/' . now()->year . '/aps-vejez.csv');
                $data = session()->get('aps_data');
                $collect = collect([]);
                $process = collect([]);
                foreach ($data as $d1) {
                    $temp = $d1;
                    // [34] PTC_DERECHOHABIENTE
                    if ((is_null($d1[34]) || $d1[34] == 'C') && !$process->contains($d1[0])) {
                        foreach ($data as $d2) {
                            // if ($d1[3] == $d2[3] && $d1[10] == $d2[10] && ($d2[34] == 'C' || is_null($d2[34])) && $d1[0] != $d2[0]) {
                            if ($d1[3] == $d2[3] && ($d2[34] == 'C' || is_null($d2[34])) && $d1[0] != $d2[0]) {
                                $temp[13] =  Util::verifyAndParseNumber($temp[13]) + Util::verifyAndParseNumber($d2[13]); //TOTAL_CC
                                $temp[19] =  Util::verifyAndParseNumber($temp[19]) + Util::verifyAndParseNumber($d2[19]); //TOTAL_FSA
                                $temp[25] =  Util::verifyAndParseNumber($temp[25]) + Util::verifyAndParseNumber($d2[25]); //TOTAL_FS
                                $process->push($d2[0]);
                            }
                        }
                        $temp[13] = Util::verifyAndParseNumber($temp[13]);
                        $temp[19] = Util::verifyAndParseNumber($temp[19]);
                        $temp[25] = Util::verifyAndParseNumber($temp[25]);
                        $collect->push($temp);
                    }
                }
                logger("Total Datos del Excel " . $collect->count());
                $eco_coms = EconomicComplement::with('affiliate')
                    ->select('economic_complements.*')
                    ->leftJoin('affiliates', 'economic_complements.affiliate_id', '=', 'affiliates.id')
                    ->where('affiliates.pension_entity_id', '<>', 5)
                    ->where('eco_com_procedure_id', 14)
                    ->NotHasEcoComState(1, 4, 6)
                    ->get();
                foreach ($eco_coms as $e) {
                    foreach ($collect as $c) {
                        // logger($e->affiliate->identity_card);
                        // logger($c[3]);
                        $affiliate_ci_eco_com = explode("-", ltrim($e->affiliate->identity_card, "0"))[0];
                        // $affiliate_ci_eco_com = ltrim($e->affiliate->identity_card, "0");
                        $ci_aps = explode("-", ltrim($c[10], "0"))[0];
                        // $ci_aps = ltrim($c[10], "0");
                        // if ($ci_aps == $affiliate_ci_eco_com && $c[3] == $e->affiliate->nua) {
                        if ($c[3] == $e->affiliate->nua) {
                            // if ($e->aps_total_cc <> round($c[13], 2) || $e->aps_total_fsa <> round($c[19], 2) || $e->aps_total_fs <> round($c[25], 2)) {
                            // if ($sw_override) {
                            $e->aps_total_cc = round($c[13], 2);
                            $e->aps_total_fsa = round($c[19], 2);
                            $e->aps_total_fs = round($c[25], 2);
                            $e->rent_type = 'Automatico';
                            $e->save();
                            $e->calculateTotalRentAps();
                            $success++;
                        }
                    }
                }
                foreach ($collect as $c) {
                    $ci_aps = explode("-", ltrim($c[10], "0"))[0];
                    $affiliate = Affiliate::whereRaw("split_part(ltrim(trim(affiliates.identity_card),'0'), '-', 1) ='" . ltrim(trim($ci_aps), '0') . "'")
                        ->where('nua', $c[3])->first();
                    if ($affiliate) {
                        if (!$affiliate->hasEconomicComplementWithProcedure(14)) {
                            $not_has_eco_com->push($affiliate);
                        }
                    } else {
                        $not_found_db->push($c);
                    }
                }
                $not_found = EconomicComplement::with('eco_com_beneficiary')->select('economic_complements.*')
                    ->leftJoin('affiliates', 'economic_complements.affiliate_id', '=', 'affiliates.id')
                    ->where('eco_com_procedure_id', 14)
                    ->where('affiliates.pension_entity_id', '<>', 5)
                    ->where('rent_type', '<>', 'Automatico')
                    ->where(function ($query) {
                        $query->whereNull('economic_complements.total_rent')
                            ->orWhere('economic_complements.total_rent', '=', 0);
                    })
                    ->get();

                break;
            case 'invalidez':
                if (!$sw_refresh) {
                    $uploadedFile = $request->file('invalidez');
                    $filename = 'aps-invalidez.' . $uploadedFile->getClientOriginalExtension();
                    Storage::disk('local')->putFileAs(
                        'aps/' . now()->year,
                        $uploadedFile,
                        $filename
                    );
                };
                Excel::import(new EcoComImportAPS, 'aps/' . now()->year . '/aps-invalidez.csv');
                $data = session()->get('aps_data');
                $collect = collect([]);
                $process = collect([]);
                foreach ($data as $d1) {
                    $temp = $d1;
                    // foreach ($data as $d2) {
                    //     if ($d1[3] == $d2[3] && ($d2[34] == 'C' || is_null($d2[34])) && $d1[0] != $d2[0]) {
                    //         $temp[13] =  Util::verifyAndParseNumber($temp[13]) + Util::verifyAndParseNumber($d2[13]); //TOTAL_CC
                    //         $temp[19] =  Util::verifyAndParseNumber($temp[19]) + Util::verifyAndParseNumber($d2[19]); //TOTAL_FSA
                    //         $temp[25] =  Util::verifyAndParseNumber($temp[25]) + Util::verifyAndParseNumber($d2[25]); //TOTAL_FS
                    //         $process->push($d2[0]);
                    //     }
                    // }
                    $temp[16] = Util::verifyAndParseNumber($temp[16]);
                    $collect->push($temp);
                }
                // logger($collect->count());
                $eco_coms = EconomicComplement::with('affiliate')->select('economic_complements.*')
                    ->leftJoin('affiliates', 'economic_complements.affiliate_id', '=', 'affiliates.id')
                    ->where('affiliates.pension_entity_id', '<>', 5)
                    ->where('eco_com_procedure_id', 14)
                    ->NotHasEcoComState(1, 4, 6)
                    ->get();
                $fails = collect([]);
                foreach ($eco_coms as $e) {
                    foreach ($collect as $c) {
                        $affiliate_ci_eco_com = explode("-", ltrim($e->affiliate->identity_card, "0"))[0];
                        // $affiliate_ci_eco_com = ltrim($e->affiliate->identity_card, "0");
                        $ci_aps = explode("-", ltrim($c[10], "0"))[0];
                        // $ci_aps = ltrim($c[10], "0");
                        // if ($ci_aps == $affiliate_ci_eco_com && $c[3] == $e->affiliate->nua) {
                        if ($c[3] == $e->affiliate->nua) {
                            // if ($e->aps_disability <> round($c[16], 2)) {
                            //     if ($sw_override) {
                            $e->aps_disability = round($c[16], 2);
                            $e->save();
                            $e->calculateTotalRentAps();
                            $success++;
                        }
                    }
                }
                $temp = 0;
                foreach ($collect as $c) {
                    if ($temp > 0) {
                        $ci_aps = explode("-", ltrim($c[10], "0"))[0];
                        $affiliate = Affiliate::whereRaw("split_part(ltrim(trim(affiliates.identity_card),'0'), '-', 1) ='" . ltrim(trim($ci_aps), '0') . "'")
                            ->where('nua', $c[3])->first();
                        if ($affiliate) {
                            if (!$affiliate->hasEconomicComplementWithProcedure(14)) {
                                $not_has_eco_com->push($affiliate);
                            }
                        } else {
                            $not_found_db->push($c);
                        }
                    }
                    $temp++;
                }
                $not_found = EconomicComplement::with('eco_com_beneficiary')->select('economic_complements.*')
                    ->leftJoin('affiliates', 'economic_complements.affiliate_id', '=', 'affiliates.id')
                    ->where('eco_com_procedure_id', 14)
                    ->where('affiliates.pension_entity_id', '<>', 5)
                    ->where('rent_type', '<>', 'Automatico')
                    ->where(function ($query) {
                        $query->whereNull('economic_complements.total_rent')
                            ->orWhere('economic_complements.total_rent', '=', 0);
                    })
                    ->get();
                break;

            case 'muerte':
                if (!$sw_refresh) {
                    $uploadedFile = $request->file('muerte');
                    $filename = 'aps-muerte.' . $uploadedFile->getClientOriginalExtension();
                    Storage::disk('local')->putFileAs(
                        'aps/' . now()->year,
                        $uploadedFile,
                        $filename
                    );
                };
                Excel::import(new EcoComImportAPS, 'aps/' . now()->year . '/aps-muerte.csv');
                $data = session()->get('aps_data');
                $collect = collect([]);
                $process = collect([]);
                foreach ($data as $d1) {
                    $temp = $d1;
                    if ((is_null($d1[27]) || $d1[27] == 'C') && !$process->contains($d1[0])) {
                        foreach ($data as $d2) {
                            // if ($d1[3] == $d2[3] && $d1[11] == $d2[11] && ($d2[27] == 'C' || is_null($d2[27])) && $d1[0] != $d2[0]) {
                            if ($d1[3] == $d2[3] && ($d2[27] == 'C' || is_null($d2[27])) && $d1[0] != $d2[0]) {
                                $temp[17] =  Util::verifyAndParseNumber($temp[17]) + Util::verifyAndParseNumber($d2[17]);
                                $process->push($d2[0]);
                            }
                        }
                        $temp[17] = Util::verifyAndParseNumber($temp[17]);
                        $collect->push($temp);
                    }
                }
                $eco_coms = EconomicComplement::with('affiliate')->select('economic_complements.*')
                    ->leftJoin('affiliates', 'economic_complements.affiliate_id', '=', 'affiliates.id')
                    ->where('affiliates.pension_entity_id', '<>', 5)
                    ->where('eco_com_procedure_id', 14)
                    ->NotHasEcoComState(1, 4, 6)
                    ->get();
                $fails = collect([]);
                foreach ($eco_coms as $e) {
                    foreach ($collect as $c) {
                        $affiliate_ci_eco_com = explode("-", ltrim($e->affiliate->identity_card, "0"))[0];
                        $ci_aps = explode("-", ltrim($c[11], "0"))[0];
                        // if ($ci_aps == $affiliate_ci_eco_com && $c[3] == $e->affiliate->nua) {
                        if ($c[3] == $e->affiliate->nua) {
                            $e->aps_total_death = round($c[17], 2);
                            $e->save();
                            $e->calculateTotalRentAps();
                            $success++;
                        }
                    }
                }
                $temp = 0;
                foreach ($collect as $c) {
                    if ($temp > 0) {
                        $ci_aps = ltrim($c[11], "0");
                        $affiliate = Affiliate::whereRaw("split_part(ltrim(trim(affiliates.identity_card),'0'), '-', 1) ='" . ltrim(trim($ci_aps), '0') . "'")
                            ->where('nua', $c[3])->first();
                        if ($affiliate) {
                            if (!$affiliate->hasEconomicComplementWithProcedure(14)) {
                                $not_has_eco_com->push($affiliate);
                            }
                        } else {
                            $not_found_db->push($c);
                        }
                    }
                    $temp++;
                }
                $not_found = EconomicComplement::with('eco_com_beneficiary')->select('economic_complements.*')
                    ->leftJoin('affiliates', 'economic_complements.affiliate_id', '=', 'affiliates.id')
                    ->where('eco_com_procedure_id', 14)
                    ->where('affiliates.pension_entity_id', '<>', 5)
                    ->where('rent_type', '<>', 'Automatico')
                    ->where(function ($query) {
                        $query->whereNull('economic_complements.total_rent')
                            ->orWhere('economic_complements.total_rent', '=', 0);
                    })
                    ->get();
                break;
            default:
                # code...
                break;
        }
        $data = [
            'success' => $success,
            'csvTotal' => $collect->count() - 1,
            'notHasEcoCom' => $not_has_eco_com,
            'notFoundDB' => $not_found_db,
            'notFound' => $not_found,
        ];
        return $data;
        // $no_import = EconomicComplement::with('eco_com_beneficiary')->select('economic_complements.*')
        //     ->leftJoin('affiliates', 'economic_complements.affiliate_id', '=', 'affiliates.id')
        //     ->where('eco_com_procedure_id',14)
        //     ->where('rent_type','<>','Automatico')
        //     ->where('affiliates.pension_entity_id',5)
        //     ->get();
        // return array_merge(session()->get('senasir_data'), ['not_found'=>$no_import]);
    }
    public function importPagoFuturo(Request $request)
    {
        logger($request->all());
        if ($request->refresh != 'true') {
            $uploadedFile = $request->file('image');
            $filename = 'pago_futuro.' . $uploadedFile->getClientOriginalExtension();
            Storage::disk('local')->putFileAs(
                'pago_futuro/' . now()->year,
                $uploadedFile,
                $filename
            );
        }
        Excel::import(new EcoComImportPagoFuturo, 'pago_futuro/' . now()->year . '/pago_futuro.csv');
        return session()->get('pago_futuro_data');
        // return array_merge(session()->get('senasir_data'), []);

    }
}
