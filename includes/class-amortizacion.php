<?php
/**
 * Utilidad central para calcular amortizaciones bajo sistema alemán.
 *
 * Contiene la lógica compartida para importaciones, desembolsos y documentos PDF.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LUD_Amortizacion {

    /**
     * Redondea un valor hacia arriba al múltiplo de 1.000 más cercano.
     */
    private static function redondear_a_mil( $valor ) {
        $valor = floatval( $valor );
        if ( $valor <= 0 ) {
            return 0;
        }
        return ceil( $valor / 1000 ) * 1000;
    }

    /**
     * Calcula la fecha de la primera cuota según estatutos (día 5 del mes correspondiente).
     */
    public static function calcular_fecha_primera_cuota( $fecha_inicio, $tipo_credito ) {
        try {
            $fecha = new DateTime( $fecha_inicio );
        } catch ( Exception $e ) {
            return '';
        }

        $meses = $tipo_credito === 'agil' ? 1 : 2;
        $fecha->modify( "+{$meses} months" );
        $fecha->setDate( $fecha->format( 'Y' ), $fecha->format( 'm' ), 5 );

        return $fecha->format( 'Y-m-d' );
    }

    /**
     * Calcula el interés prorrateado por días para la primera cuota.
     */
    public static function calcular_interes_prorrateado( $saldo, $tasa, $fecha_inicio, $fecha_vencimiento ) {
        try {
            $inicio = new DateTime( $fecha_inicio );
            $vencimiento = new DateTime( $fecha_vencimiento );
        } catch ( Exception $e ) {
            return round( $saldo * ( $tasa / 100 ), 2 );
        }

        $dias = (int) $inicio->diff( $vencimiento )->format( '%a' );
        if ( $dias <= 0 ) {
            return round( $saldo * ( $tasa / 100 ), 2 );
        }

        $factor_diario = ( $tasa / 100 ) / 30;
        return round( $saldo * $factor_diario * $dias, 2 );
    }

    /**
     * Construye la tabla de amortización alemana con prorrateo en la primera cuota.
     */
    public static function construir_tabla_amortizacion( $monto, $tasa, $plazo, $fecha_inicio, $tipo_credito, $capital_pagado = 0 ) {
        $monto = floatval( $monto );
        $tasa = floatval( $tasa );
        $plazo = intval( $plazo );

        if ( $plazo <= 0 ) {
            return array(
                'cuotas' => array(),
                'resumen' => array(),
            );
        }

        $capital_mensual_base = round( $monto / $plazo, 2 );
        $suma_capitales = $capital_mensual_base * $plazo;
        $diferencia = round( $monto - $suma_capitales, 2 );

        $saldo = $monto;
        $fecha_base = self::calcular_fecha_primera_cuota( $fecha_inicio, $tipo_credito );
        $capital_pagado = max( 0, floatval( $capital_pagado ) );
        $capital_pagado_acumulado = 0;
        $interes_total = 0;
        $total_general = 0;
        $fecha_primera = '';
        $fecha_vencimiento_final = '';
        $cuotas = array();

        for ( $i = 1; $i <= $plazo; $i++ ) {
            $fecha_vencimiento = $fecha_base;
            if ( $i > 1 && $fecha_base ) {
                $fecha = new DateTime( $fecha_base );
                $fecha->modify( '+' . ( $i - 1 ) . ' months' );
                $fecha->setDate( $fecha->format( 'Y' ), $fecha->format( 'm' ), 5 );
                $fecha_vencimiento = $fecha->format( 'Y-m-d' );
            }

            if ( $i === 1 ) {
                $fecha_primera = $fecha_vencimiento;
            }
            if ( $i === $plazo ) {
                $fecha_vencimiento_final = $fecha_vencimiento;
            }

            $capital_cuota_base = $capital_mensual_base;
            if ( $i === $plazo ) {
                $capital_cuota_base = round( $capital_cuota_base + $diferencia, 2 );
            }

            $interes_cuota_base = $i === 1
                ? self::calcular_interes_prorrateado( $saldo, $tasa, $fecha_inicio, $fecha_vencimiento )
                : round( $saldo * ( $tasa / 100 ), 2 );
            $capital_cuota = self::redondear_a_mil( $capital_cuota_base );
            $interes_cuota = self::redondear_a_mil( $interes_cuota_base );
            $valor_cuota_total = self::redondear_a_mil( $capital_cuota_base + $interes_cuota_base );

            $interes_total += $interes_cuota;
            $total_general += $valor_cuota_total;

            $estado = 'pendiente';
            $fecha_pago = null;
            $monto_pagado = 0;
            $capital_pagado_acumulado += $capital_cuota;
            if ( $capital_pagado > 0 && $capital_pagado_acumulado <= $capital_pagado + 0.01 ) {
                $estado = 'pagado';
                $fecha_pago = $fecha_vencimiento;
                $monto_pagado = $valor_cuota_total;
            }

            $cuotas[] = array(
                'numero' => $i,
                'fecha_vencimiento' => $fecha_vencimiento,
                'capital' => $capital_cuota,
                'interes' => $interes_cuota,
                'total' => $valor_cuota_total,
                'estado' => $estado,
                'fecha_pago' => $fecha_pago,
                'monto_pagado' => $monto_pagado,
            );

            $saldo -= $capital_cuota_base;
        }

        return array(
            'cuotas' => $cuotas,
            'resumen' => array(
                'interes_total' => round( $interes_total, 2 ),
                'total_general' => round( $total_general, 2 ),
                'fecha_primera' => $fecha_primera,
                'fecha_vencimiento' => $fecha_vencimiento_final,
                'cuota_inicial' => isset( $cuotas[0] ) ? $cuotas[0]['total'] : 0,
                'capital_base' => $capital_mensual_base,
            ),
        );
    }
}
