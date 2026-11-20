<?php

namespace App\Services;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VehicleRegistry
{
    public const FUEL_TYPES = ['diesel','petrol','lpg','cng','lng','bev','fcev','hydrogen_combustion','biodiesel','ethanol','biomethane','alternative_other','petrol_hev','diesel_hev','petrol_phev','diesel_phev','petrol_lpg','petrol_cng','hybrid_other'];
    public const COUNTRIES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';
    public const TYPES = ['cargo_van','rigid_truck','tractor_unit','semi_trailer','trailer'];
    public const BODIES = ['panel_van','box','curtainsider','tarpaulin','refrigerated','flatbed','dropside','container_chassis','tipper','tanker','silo','car_transporter','swap_body','low_loader','other','none'];
    public const NUMBERS = ['gross_weight_kg','unladen_weight_kg','category_mass_kg','gross_combination_weight_kg','max_towed_weight_kg','fifth_wheel_load_kg','length_m','width_m','height_m','cargo_length_m','cargo_width_m','cargo_height_m','door_width_m','door_height_m','loading_metres','tank_capacity_l','tail_lift_capacity_kg'];

    public static function isTrailer(?array $features): bool { return in_array($features['resource_type']??'', ['semi_trailer','trailer'],true); }

    public static function category(float $mass, bool $trailer = false): string
    {
        return $trailer ? ($mass<=750?'O1':($mass<=3500?'O2':($mass<=10000?'O3':'O4'))) : ($mass<=3500?'N1':($mass<=12000?'N2':'N3'));
    }

    /** Merge a partial update, keep unknown legacy data, and validate every structured registry write. */
    public function validate(array $data, ?Vehicle $existing = null): array
    {
        $incoming=$data['features']??[];
        $common=[];
        $common['mass_conditions']=['nullable','string','max:4000'];
        foreach(['last_service','next_service'] as $key) if(array_key_exists($key,$incoming) && ($incoming[$key]??null)!==($existing?->features[$key]??null)) $common[$key]=['nullable','date_format:Y-m-d'];
        if(array_key_exists('fuel_type',$incoming) && ($incoming['fuel_type']??null)!==($existing?->features['fuel_type']??null)) $common['fuel_type']=['nullable',Rule::in(self::FUEL_TYPES)];
        if(array_key_exists('operation_country',$incoming) && ($incoming['operation_country']??null)!==($existing?->features['operation_country']??null)) $common['operation_country']=['nullable',Rule::in(explode(' ',self::COUNTRIES))];
        Validator::make($incoming,$common)->validate();
        Validator::make($data,['capacity_kg'=>['nullable','numeric','min:0','max:1000000000'],'capacity_m3'=>['nullable','numeric','min:0','max:1000000000']])->validate();
        if (array_key_exists('features',$data)) $data['features']=array_replace($existing?->features??[], $data['features']??[]);
        $f=$data['features']??$existing?->features??[];
        $road=($data['transport_type']??$existing?->transport_type??'road')==='road';
        if (!$road) {
            if (!empty($f['resource_type']) && in_array($f['resource_type'],self::TYPES,true)) throw ValidationException::withMessages(['features.resource_type'=>['Road categories cannot be assigned to aircraft or ships.']]);
            return $data;
        }
        // Older clients remain readable/editable. An explicit classification must use stable codes.
        if (empty($f['resource_type'])) return $data;
        $rules=['resource_type'=>['required',Rule::in(self::TYPES)],'legal_category'=>['nullable',Rule::in(['N1','N2','N3','O1','O2','O3','O4'])],
            'body_type_code'=>['required',Rule::in(self::BODIES)],'body_variant'=>['nullable',Rule::in(['standard','mega','high_cube','double_deck','coil_well'])],
            'axle_configuration'=>['nullable','in:4x2,4x4,6x2,6x4,6x6,8x2,8x4,8x6,8x8,2_axles,3_axles,4_axles,5_axles,6_axles'],
            'wheelbase_variant'=>['nullable','string','max:40'],'roof_variant'=>['nullable','string','max:40'],
            'pallet_positions'=>['nullable','integer','between:0,250'],'compartments'=>['nullable','integer','between:1,20'],
            'temperature_min_c'=>['nullable','numeric','between:-100,100'],'temperature_max_c'=>['nullable','numeric','between:-100,100'],
            'loading_methods'=>['sometimes','array'],'loading_methods.*'=>['in:rear,side,top,crane,ground'],
            'operation_country'=>['nullable','regex:/^[A-Z]{2}$/'],'operation_scope'=>['nullable','in:domestic,international'],
            'operation_kind'=>['nullable','in:own_account,hire_reward'],'adr_applicability'=>['nullable','in:unknown,yes,no'],
            'atp_applicability'=>['nullable','in:unknown,yes,no'],'container_module'=>['nullable','in:none,20ft,40ft,40hc,45ft,other']];
        foreach(self::NUMBERS as $key)$rules[$key]=['nullable','numeric','gt:0','max:1000000'];
        Validator::make($f,$rules)->validate();
        $trailer=self::isTrailer($f);$tractor=$f['resource_type']==='tractor_unit';
        $fail=fn($key,$message)=>throw ValidationException::withMessages([$key=>[$message]]);
        if($f['resource_type']==='cargo_van'&&!in_array($f['body_type_code'],['panel_van','box','curtainsider','tarpaulin','refrigerated','dropside','tipper','other'],true))$fail('features.body_type_code','This body is not compatible with a cargo van.');
        if($f['resource_type']!=='cargo_van'&&$f['body_type_code']==='panel_van')$fail('features.body_type_code','A panel van body requires a cargo van.');
        if(!empty($f['legal_category'])&&str_starts_with($f['legal_category'],'O')!==$trailer)$fail('features.legal_category','Use N categories for motor vehicles and O categories for trailers.');
        $mass=$f['resource_type']==='semi_trailer'?($f['category_mass_kg']??null):($f['gross_weight_kg']??null);
        if($mass&&!empty($f['legal_category'])&&self::category((float)$mass,$trailer)!==$f['legal_category'])$fail('features.legal_category','The declared mass does not match the homologation category.');
        $gross=$f['gross_weight_kg']??null;$tare=$f['unladen_weight_kg']??null;
        $payload=$data['capacity_kg']??(array_key_exists('capacity_kg',$data)?null:$existing?->capacity_kg);
        if($gross&&$tare&&$tare>$gross)$fail('features.unladen_weight_kg','Vehicle mass cannot exceed its maximum permissible mass.');
        if($gross&&$payload!==null&&($payload>$gross||($tare&&$payload>(float)$gross-(float)$tare+.01)))$fail('capacity_kg','Payload exceeds the declared mass allowance.');
        if($tractor&&($payload!==null||!empty($data['capacity_m3'])||!empty($f['cargo_length_m'])))$fail('capacity_kg','A tractor unit has no cargo-body payload; register its semi-trailer separately.');
        if($tractor!==($f['body_type_code']==='none'))$fail('features.body_type_code','Only a tractor unit uses no cargo body.');
        if($trailer&&!empty($data['assigned_driver_user_id']??$existing?->assigned_driver_user_id))$fail('assigned_driver_user_id','Assign the driver to the towing vehicle, not to a trailer.');
        foreach(['length','width','height'] as $axis)if(!empty($f['cargo_'.$axis.'_m'])&&!empty($f[$axis.'_m'])&&$f['cargo_'.$axis.'_m']>$f[$axis.'_m'])$fail('features.cargo_'.$axis.'_m','Internal cargo dimensions cannot exceed overall dimensions.');
        foreach(['width','height'] as $axis)if(!empty($f['door_'.$axis.'_m'])&&!empty($f['cargo_'.$axis.'_m'])&&$f['door_'.$axis.'_m']>$f['cargo_'.$axis.'_m'])$fail('features.door_'.$axis.'_m','Door opening cannot exceed the cargo space.');
        if(isset($f['temperature_min_c'],$f['temperature_max_c'])&&$f['temperature_min_c']>$f['temperature_max_c'])$fail('features.temperature_max_c','Maximum temperature must not be below minimum temperature.');
        $f['registry_version']=1;
        // A registration or a document upload never grants a verified compliance status.
        unset($f['compliance_verified'],$f['compliance_status']);
        $data['features']=$f;
        return $data;
    }

    public static function warnings(Vehicle $vehicle): array
    {
        if($vehicle->transport_type&&$vehicle->transport_type!=='road')return [];
        $f=$vehicle->features??[];$warnings=[];
        if(empty($f['resource_type']))$warnings[]='classification_unconfirmed';
        if(empty($f['legal_category']))$warnings[]='category_unconfirmed';
        if((float)$vehicle->capacity_kg>100000||(float)$vehicle->capacity_m3>300)$warnings[]='capacity_review';
        if(!empty($f['resource_type'])&&preg_match('/^Yes/i',(string)($f['trailer']??'')))$warnings[]='legacy_trailer_unlinked';
        return $warnings;
    }

    public static function guidance(?array $f): array
    {
        $f??=[];$items=['registration','technical_inspection'];
        if(self::isTrailer($f))return ['status'=>'pending_review','documents'=>$items,'note'=>'trailer_documents'];
        $eu=explode(' ','AT BE BG HR CY CZ DK EE FI FR DE GR HU IE IT LV LT LU MT NL PL PT RO SK SI ES SE');
        if(in_array($f['operation_country']??'',$eu,true)&&($f['operation_scope']??'')==='international'&&($f['operation_kind']??'')==='hire_reward'&&max((float)($f['gross_weight_kg']??0),(float)($f['gross_combination_weight_kg']??0))>2500)$items[]='community_licence_copy';
        elseif(($f['operation_scope']??'')==='international')$items[]='transport_authorisation_review';
        if(($f['atp_applicability']??'unknown')==='yes')$items[]='atp_certificate';
        if(($f['adr_applicability']??'unknown')==='yes')$items[]='adr_evidence';
        return ['status'=>'pending_review','documents'=>$items,'note'=>'country_operation_review'];
    }
}
