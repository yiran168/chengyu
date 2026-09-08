<?php
declare(strict_types=1);
namespace Chengyu\Core;

/** Shared motion contracts for validation, the inspector and rendered pages. */
final class Motion
{
    public const CURVES = [
        'gentle'=>[0.22,1.0,0.36,1.0], 'snappy'=>[0.2,0.8,0.2,1.0],
        'elastic'=>[0.34,1.56,0.64,1.0], 'cinematic'=>[0.76,0.0,0.24,1.0],
        'linear'=>[0.0,0.0,1.0,1.0],
    ];
    public const KEYS = ['motion_layout','motion_navigation','motion_feedback','motion_images','motion_response','spring_stiffness','spring_damping','spring_mass','motion_disclosures','motion','motion_adaptive','motion_curve','motion_duration','hover_lift','ambient','grain','particles','reveal','ripple','page_transition',
        'motion_reveal_style','motion_stagger','motion_distance','motion_spotlight','motion_tilt','motion_tilt_degrees','motion_magnetic','motion_magnetic_distance','motion_sheen','motion_parallax','motion_theme_transition','motion_continuous','curve_x1','curve_y1','curve_x2','curve_y2'];
    public static function curve(Settings $s): array
    {
        if ($s->get('motion_curve')==='custom') { return array_map(static function($key)use($s):float{return (int)$s->get($key)/100;},['curve_x1','curve_y1','curve_x2','curve_y2']); }
        return self::CURVES[$s->get('motion_curve')]??self::CURVES['gentle'];
    }
    public static function presets(): array
    {
        return [
            'quiet'=>['motion_response'=>'curve','spring_stiffness'=>220,'spring_damping'=>30,'spring_mass'=>100,'motion'=>true,'motion_curve'=>'gentle','motion_duration'=>280,'hover_lift'=>2,'ambient'=>false,'particles'=>false,'motion_tilt'=>false,'motion_magnetic'=>false,'motion_parallax'=>false,'motion_continuous'=>false,'motion_reveal_style'=>'rise','motion_stagger'=>25,'motion_distance'=>8,'motion_sheen'=>false,'motion_theme_transition'=>'crossfade','motion_spotlight'=>false],
            'balanced'=>['motion_response'=>'spring','spring_stiffness'=>180,'spring_damping'=>24,'spring_mass'=>100,'motion'=>true,'motion_curve'=>'gentle','motion_duration'=>480,'hover_lift'=>5,'ambient'=>true,'particles'=>false,'motion_tilt'=>true,'motion_magnetic'=>false,'motion_parallax'=>true,'motion_continuous'=>true,'motion_reveal_style'=>'rise','motion_stagger'=>55,'motion_distance'=>18,'motion_sheen'=>true,'motion_theme_transition'=>'iris','motion_spotlight'=>true],
            'expressive'=>['motion_response'=>'spring','spring_stiffness'=>160,'spring_damping'=>17,'spring_mass'=>110,'motion'=>true,'motion_curve'=>'elastic','motion_duration'=>650,'hover_lift'=>7,'ambient'=>true,'particles'=>true,'motion_tilt'=>true,'motion_magnetic'=>true,'motion_parallax'=>true,'motion_continuous'=>true,'motion_reveal_style'=>'scale','motion_stagger'=>80,'motion_distance'=>22,'motion_sheen'=>true,'motion_theme_transition'=>'iris','motion_spotlight'=>true],
        ];
    }
}
