<?php
/**
 * Motor centralizado de notificaciones por correo del Fondo La Unión.
 *
 * Gestiona plantillas, configuración editable desde el administrador,
 * y tareas automáticas (recordatorios de mora, actualizaciones de datos
 * y resúmenes mensuales para la directiva).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LUD_Notificaciones {

    /**
     * Nombre de la opción que almacena la configuración editable.
     */
    private $opcion_configuracion = 'lud_ajustes_correos';

    /**
     * Identificadores de tareas programadas (cron) para orquestar los envíos automáticos.
     */
    private $evento_diario = 'lud_tarea_correos_diarios';
    private $evento_mensual = 'lud_tarea_resumen_directiva';
    private $evento_datos = 'lud_tarea_recordatorio_datos';

    /**
     * Constructor: registra hooks de acciones de negocio y tareas automáticas.
     */
    public function __construct() {
        add_action( 'init', array( $this, 'programar_tareas_automaticas' ) );

        // Eventos del negocio
        add_action( 'lud_evento_pago_reportado', array( $this, 'manejar_pago_reportado' ), 10, 3 );
        add_action( 'lud_evento_pago_aprobado', array( $this, 'manejar_pago_aprobado' ), 10, 4 );
        add_action( 'lud_evento_pago_rechazado', array( $this, 'manejar_pago_rechazado' ), 10, 3 );
        add_action( 'lud_evento_credito_solicitado', array( $this, 'manejar_credito_solicitado' ), 10, 3 );
        add_action( 'lud_evento_credito_deudor', array( $this, 'manejar_correo_deudor' ), 10, 5 );
        add_action( 'lud_evento_credito_decision', array( $this, 'manejar_decision_credito' ), 10, 5 );
        add_action( 'lud_evento_datos_actualizados', array( $this, 'manejar_actualizacion_datos' ), 10, 3 );
        add_action( 'lud_evento_retiro', array( $this, 'manejar_retiro' ), 10, 4 );

        // Tareas automáticas
        add_action( $this->evento_diario, array( $this, 'procesar_recordatorios_diarios' ) );
        add_action( $this->evento_mensual, array( $this, 'enviar_resumen_mensual_directiva' ) );
        add_action( $this->evento_datos, array( $this, 'recordar_actualizacion_datos' ) );
    }

    /**
     * Devuelve la configuración actual uniendo valores almacenados con defaults seguros.
     */
    public function obtener_configuracion() {
        $predeterminado = array(
            'logo_url'                   => '',
            'url_portal'                 => home_url(),
            'texto_pie'                  => 'Fondo La Unión Digital - Este mensaje es automático.',
            'enlace_politicas'           => home_url( '/politicas' ),
            'enlace_actualizacion_datos' => admin_url( 'profile.php' ),
            'remitente_nombre'           => 'Fondo La Unión Digital'
        );

        $guardado = get_option( $this->opcion_configuracion, array() );
        return array_merge( $predeterminado, is_array( $guardado ) ? $guardado : array() );
    }

    /**
     * Guarda la configuración de correos a nivel de opción de WordPress.
     *
     * @param array $datos Valores sanitizados desde el formulario.
     */
    public function guardar_configuracion( $datos ) {
        update_option( $this->opcion_configuracion, $datos );
    }

    /**
     * Programa los eventos cron si aún no existen.
     */
    public function programar_tareas_automaticas() {
        if ( ! wp_next_scheduled( $this->evento_diario ) ) {
            wp_schedule_event( time(), 'daily', $this->evento_diario );
        }

        if ( ! wp_next_scheduled( $this->evento_mensual ) ) {
            wp_schedule_event( time(), 'daily', $this->evento_mensual );
        }

        if ( ! wp_next_scheduled( $this->evento_datos ) ) {
            wp_schedule_event( time(), 'daily', $this->evento_datos );
        }
    }

    /**
     * Crea el saludo obligatorio con nombre y documento.
     */
    private function obtener_saludo( $user_id ) {
        global $wpdb;
        $usuario = get_userdata( $user_id );
        $cuenta  = $wpdb->get_row( $wpdb->prepare( "SELECT tipo_documento, numero_documento FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d", $user_id ) );

        $documento = $cuenta ? trim( $cuenta->tipo_documento . ' ' . $cuenta->numero_documento ) : '';
        $nombre    = $usuario ? $usuario->display_name : 'Socio';

        return trim( "Hola {$nombre} {$documento}" );
    }

    /**
     * Arma la plantilla HTML común reutilizada en todos los correos.
     * Público para reutilizar la misma estética en vistas previas y pruebas.
     */
    public function armar_html( $user_id, $titulo, $contenido, $cta = array() ) {
        $config  = $this->obtener_configuracion();
        $saludo  = $this->obtener_saludo( $user_id );
        $logo    = $config['logo_url'] ? '<img src="' . esc_url( $config['logo_url'] ) . '" alt="Logo Fondo La Unión" style="max-width:180px; margin-bottom:20px;">' : '';
        $boton   = '';

        if ( ! empty( $cta['texto'] ) && ! empty( $cta['url'] ) ) {
            $boton = '<div style="margin:20px 0;"><a href="' . esc_url( $cta['url'] ) . '" style="background:#1565c0;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold;">' . esc_html( $cta['texto'] ) . '</a></div>';
        }

        $html = '<div style="font-family:Arial, sans-serif; background:#f6f8fb; padding:20px;">';
        $html .= '<div style="max-width:620px; margin:0 auto; background:#fff; border-radius:8px; padding:25px; box-shadow:0 3px 12px rgba(0,0,0,0.05);">';
        $html .= $logo;
        $html .= '<p style="margin:0 0 10px 0; font-size:14px; color:#555;">' . esc_html( $saludo ) . ',</p>';
        $html .= '<h2 style="margin:5px 0 15px; color:#0d47a1;">' . esc_html( $titulo ) . '</h2>';
        $html .= '<div style="font-size:15px; color:#333; line-height:1.6;">' . wp_kses_post( $contenido ) . '</div>';
        $html .= $boton;
        $html .= '<hr style="border:0; border-top:1px solid #e0e0e0; margin:25px 0;">';
        $html .= '<p style="font-size:12px; color:#777; line-height:1.4;">' . esc_html( $config['texto_pie'] ) . '<br>';
        $html .= '<a href="' . esc_url( $config['enlace_politicas'] ) . '" style="color:#1565c0;">Políticas y privacidad</a> · ';
        $html .= '<a href="' . esc_url( $config['url_portal'] ) . '" style="color:#1565c0;">Portal La Unión</a></p>';
        $html .= '</div></div>';

        return $html;
    }

    /**
     * Envía un correo HTML con adjuntos opcionales.
     */
    public function enviar_correo( $destino, $asunto, $html, $adjuntos = array() ) {
        $config   = $this->obtener_configuracion();
        $headers  = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $config['remitente_nombre'] . ' <' . get_bloginfo( 'admin_email' ) . '>'
        );

        wp_mail( $destino, $asunto, $html, $headers, $adjuntos );
    }

    /**
     * Notifica que el pago fue reportado y quedó en revisión.
     */
    public function manejar_pago_reportado( $user_id, $tx_id, $monto ) {
        $contenido = '<p>Registramos tu comprobante por <strong>$' . number_format( $monto, 0, ',', '.' ) . '</strong>. '
                   . 'Tesorería validará y confirmará el recaudo.</p>'
                   . '<p>ID de reporte: <strong>#' . intval( $tx_id ) . '</strong></p>';

        $html = $this->armar_html( $user_id, 'Pago recibido en validación', $contenido );
        $this->enviar_correo( get_userdata( $user_id )->user_email, 'Fondo La Unión · Pago en revisión', $html );
    }

    /**
     * Notifica la aprobación del pago con desglose aplicado.
     */
    public function manejar_pago_aprobado( $user_id, $tx_id, $monto, $desglose ) {
        $lineas = '<ul style="margin:0; padding-left:18px;">';
        foreach ( $desglose as $concepto => $valor ) {
            $lineas .= '<li><strong>' . esc_html( ucfirst( str_replace( '_', ' ', $concepto ) ) ) . ':</strong> $' . number_format( $valor, 0, ',', '.' ) . '</li>';
        }
        $lineas .= '</ul>';

        $contenido = '<p>Tu pago fue confirmado. Se aplicó <strong>$' . number_format( $monto, 0, ',', '.' ) . '</strong> así:</p>' . $lineas
                   . '<p>ID de transacción: <strong>#' . intval( $tx_id ) . '</strong></p>';

        $html = $this->armar_html( $user_id, 'Pago aprobado', $contenido );
        $this->enviar_correo( get_userdata( $user_id )->user_email, 'Fondo La Unión · Pago aprobado', $html );
    }

    /**
     * Notifica rechazo de pago y motivo.
     */
    public function manejar_pago_rechazado( $user_id, $tx_id, $motivo ) {
        $contenido = '<p>Tu reporte de pago <strong>#' . intval( $tx_id ) . '</strong> fue rechazado.</p>'
                   . '<p>Motivo indicado: <strong>' . esc_html( $motivo ) . '</strong></p>'
                   . '<p>Resube el comprobante o contacta a Tesorería si necesitas ayuda.</p>';

        $html = $this->armar_html( $user_id, 'Pago rechazado', $contenido );
        $this->enviar_correo( get_userdata( $user_id )->user_email, 'Fondo La Unión · Pago rechazado', $html );
    }

    /**
     * Notifica la radicación de un crédito al solicitante.
     */
    public function manejar_credito_solicitado( $user_id, $credito_id, $datos ) {
        $fila_texto = $datos['en_fila'] ? '<p style="color:#c62828;">⏳ Sin liquidez inmediata. Tu solicitud quedó en fila y respetará el orden de llegada.</p>' : '';
        $contenido  = '<p>Registramos tu solicitud de crédito <strong>#' . intval( $credito_id ) . '</strong>.</p>'
                    . '<p>Monto: <strong>$' . number_format( $datos['monto'], 0, ',', '.' ) . '</strong><br>'
                    . 'Tipo: <strong>' . esc_html( ucfirst( $datos['tipo'] ) ) . '</strong><br>'
                    . 'Plazo: <strong>' . intval( $datos['plazo'] ) . ' mes(es)</strong></p>'
                    . $fila_texto
                    . '<p>Recibirás actualizaciones en cuanto avance a Tesorería o haya liquidez.</p>';

        $html = $this->armar_html( $user_id, 'Solicitud de crédito radicada', $contenido );
        $this->enviar_correo( get_userdata( $user_id )->user_email, 'Fondo La Unión · Solicitud de crédito', $html );
    }

    /**
     * Notifica al deudor solidario con el enlace seguro de firma.
     */
    public function manejar_correo_deudor( $deudor_id, $solicitante_id, $monto, $credito_id, $token ) {
        $deudor      = get_userdata( $deudor_id );
        $solicitante = get_userdata( $solicitante_id );
        $link        = home_url( '/zona-deudor/' ) . "?cid={$credito_id}&token={$token}";

        $contenido = '<p>' . esc_html( $solicitante->display_name ) . ' te postuló como deudor solidario para un crédito de '
                   . '<strong>$' . number_format( $monto, 0, ',', '.' ) . '</strong>.</p>'
                   . '<p>Revisa y firma la solicitud en el enlace seguro.</p>';

        $html = $this->armar_html( $deudor_id, 'Firma pendiente como deudor solidario', $contenido, array(
            'texto' => 'Revisar y firmar',
            'url'   => $link
        ) );

        $this->enviar_correo( $deudor->user_email, 'Fondo La Unión · Solicitud de deudor solidario', $html );
    }

    /**
     * Envía correos al aprobar, desembolsar o rechazar un crédito.
     */
    public function manejar_decision_credito( $user_id, $estado, $credito_id, $datos_entrega, $adjunto = '' ) {
        $correo = get_userdata( $user_id );
        $estado_legible = ucfirst( str_replace( '_', ' ', $estado ) );
        $adjuntos = array();
        if ( is_array( $adjunto ) ) {
            $adjuntos = array_filter( $adjunto );
        } elseif ( ! empty( $adjunto ) ) {
            $adjuntos[] = $adjunto;
        }

        $mensaje_base = '<p>Crédito <strong>#' . intval( $credito_id ) . '</strong> actualizado a estado <strong>' . esc_html( $estado_legible ) . '</strong>.</p>';

        if ( $estado === 'activo' ) {
            $mensaje_base .= '<p>Instrucciones de entrega: <strong>' . esc_html( $datos_entrega ) . '</strong>.</p>'
                           . '<p>Incluimos el contrato firmado y el pagaré con su carta de instrucciones en PDF para tu respaldo.</p>';
        } elseif ( $estado === 'rechazado' ) {
            $mensaje_base .= '<p>La solicitud fue rechazada. Motivo: <strong>' . esc_html( $datos_entrega ) . '</strong></p>';
        } else {
            $mensaje_base .= '<p>Detalle: <strong>' . esc_html( $datos_entrega ) . '</strong></p>';
        }

        $html = $this->armar_html( $user_id, 'Actualización de crédito', $mensaje_base );
        $this->enviar_correo( $correo->user_email, 'Fondo La Unión · Actualización de crédito', $html, $adjuntos );
    }

    /**
     * Avisa al socio cuando se actualizan datos personales o de beneficiario.
     */
    public function manejar_actualizacion_datos( $user_id, $origen, $detalle ) {
        $contenido = '<p>Actualizamos tus datos desde <strong>' . esc_html( $origen ) . '</strong>.</p>'
                   . '<p>Campos modificados: <strong>' . esc_html( $detalle ) . '</strong>.</p>'
                   . '<p>Puedes revisarlos y actualizarlos cada 6 meses para mantener tu ficha al día.</p>';

        $html = $this->armar_html( $user_id, 'Datos actualizados', $contenido, array(
            'texto' => 'Ver mis datos',
            'url'   => esc_url( $this->obtener_configuracion()['enlace_actualizacion_datos'] )
        ) );
        $this->enviar_correo( get_userdata( $user_id )->user_email, 'Fondo La Unión · Datos actualizados', $html );
    }

    /**
     * Notifica al socio el flujo de retiro voluntario (solicitud y decisión).
     */
    public function manejar_retiro( $user_id, $estado, $monto_estimado, $nota = '' ) {
        $estado_legible = ucfirst( $estado );
        $contenido = '<p>Retiro voluntario: estado <strong>' . esc_html( $estado_legible ) . '</strong>.</p>'
                   . '<p>Monto estimado: <strong>$' . number_format( $monto_estimado, 0, ',', '.' ) . '</strong>.</p>';

        if ( ! empty( $nota ) ) {
            $contenido .= '<p>Detalle: ' . esc_html( $nota ) . '</p>';
        }

        $html = $this->armar_html( $user_id, 'Actualización de retiro', $contenido );
        $this->enviar_correo( get_userdata( $user_id )->user_email, 'Fondo La Unión · Retiro voluntario', $html );
    }

    /**
     * Ejecuta recordatorios diarios (mora).
     */
    public function procesar_recordatorios_diarios() {
        $this->notificar_moras_diarias();
    }

    /**
     * Envía recordatorios diarios a créditos en mora.
     */
    private function notificar_moras_diarias() {
        global $wpdb;
        $creditos_mora = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE (estado = 'mora' OR (tipo_credito = 'agil' AND estado = 'activo'))"
        );

        if ( empty( $creditos_mora ) ) {
            return;
        }

        $hoy = date( 'Y-m-d' );
        foreach ( $creditos_mora as $credito ) {
            // Evitar reenvío múltiple en el mismo día
            $llave_meta = 'lud_mora_notificada_' . $credito->id;
            $ultima_notificacion = get_user_meta( $credito->user_id, $llave_meta, true );
            if ( $ultima_notificacion === $hoy ) {
                continue;
            }

            $saldo = floatval( $credito->saldo_actual );
            $extra = '';

            if ( $credito->tipo_credito === 'agil' && $credito->fecha_aprobacion ) {
                $fecha_venc = date_create( $credito->fecha_aprobacion );
                $fecha_venc->modify( '+1 month' );
                $hoy_dt = new DateTime();
                if ( $hoy_dt <= $fecha_venc && $credito->estado !== 'mora' ) {
                    continue; // Aún no está en mora y no se fuerza el estado.
                }

                $dias = $hoy_dt->diff( $fecha_venc )->days;
                $mora_estimada = $saldo * 0.04 * ( $dias / 30 );
                $extra = '<p>Interés por mora estimado: <strong>$' . number_format( $mora_estimada, 0, ',', '.' ) . '</strong> (4% proporcional).</p>';
                $saldo += $mora_estimada;
            }

            $contenido = '<p>Tu crédito <strong>#' . intval( $credito->id ) . '</strong> está en mora.</p>'
                       . '<p>Saldo al día de hoy: <strong>$' . number_format( $saldo, 0, ',', '.' ) . '</strong>.</p>'
                       . $extra
                       . '<p>Recuerda ponerte al día para evitar sanciones adicionales.</p>';

            $html = $this->armar_html( $credito->user_id, 'Recordatorio diario de mora', $contenido );
            $this->enviar_correo( get_userdata( $credito->user_id )->user_email, 'Fondo La Unión · Mora activa', $html );
            update_user_meta( $credito->user_id, $llave_meta, $hoy );
        }
    }

    /**
     * Envío mensual de métricas a directiva (se ejecuta el día 1).
     */
    public function enviar_resumen_mensual_directiva() {
        if ( date( 'd' ) !== '01' ) {
            return;
        }

        global $wpdb;
        $mes_anterior = date( 'm', strtotime( '-1 month' ) );
        $anio_anterior = date( 'Y', strtotime( '-1 month' ) );

        $recaudo_mes = floatval( $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle WHERE MONTH(fecha_recaudo) = %d AND YEAR(fecha_recaudo) = %d",
            $mes_anterior,
            $anio_anterior
        ) ) );

        $gasto_mes = floatval( $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(monto) FROM {$wpdb->prefix}fondo_gastos WHERE MONTH(fecha_gasto) = %d AND YEAR(fecha_gasto) = %d",
            $mes_anterior,
            $anio_anterior
        ) ) );

        $cartera = floatval( $wpdb->get_var( "SELECT SUM(saldo_actual) FROM {$wpdb->prefix}fondo_creditos WHERE estado IN ('activo','mora')" ) );
        $cartera_mora = floatval( $wpdb->get_var( "SELECT SUM(saldo_actual) FROM {$wpdb->prefix}fondo_creditos WHERE estado = 'mora'" ) );

        $contenido = '<p>Cierre del mes <strong>' . $mes_anterior . '/' . $anio_anterior . '</strong></p>'
                   . '<ul style="margin:0; padding-left:18px;">'
                   . '<li>Recaudo total: <strong>$' . number_format( $recaudo_mes, 0, ',', '.' ) . '</strong></li>'
                   . '<li>Gastos operativos: <strong>$' . number_format( $gasto_mes, 0, ',', '.' ) . '</strong></li>'
                   . '<li>Cartera vigente: <strong>$' . number_format( $cartera, 0, ',', '.' ) . '</strong></li>'
                   . '<li>Cartera en mora: <strong>$' . number_format( $cartera_mora, 0, ',', '.' ) . '</strong></li>'
                   . '</ul>'
                   . '<p>Este resumen es automático para Presidencia, Secretaría y Tesorería.</p>';

        $destinos = $this->obtener_correos_directiva();
        if ( empty( $destinos ) ) {
            return;
        }

        foreach ( $destinos as $correo ) {
            $html = $this->armar_html( $correo['id'], 'Cierre mensual del fondo', $contenido );
            $this->enviar_correo( $correo['email'], 'Fondo La Unión · Resumen mensual', $html );
        }
    }

    /**
     * Envía recordatorios de actualización de datos cada 6 meses (con repetición mensual si persiste).
     */
    public function recordar_actualizacion_datos() {
        $socios = get_users( array( 'role__in' => array( 'lud_socio' ) ) );
        $hoy    = new DateTime();

        foreach ( $socios as $socio ) {
            $ultima = get_user_meta( $socio->ID, 'lud_ultima_actualizacion_datos', true );
            $ultima_dt = $ultima ? new DateTime( $ultima ) : new DateTime( $socio->user_registered );
            $meses = $ultima_dt->diff( $hoy )->m + ( $ultima_dt->diff( $hoy )->y * 12 );

            if ( $meses < 6 ) {
                continue;
            }

            $ultima_recordatorio = get_user_meta( $socio->ID, 'lud_ultima_nota_datos', true );
            if ( $ultima_recordatorio && ( strtotime( $ultima_recordatorio ) > strtotime( '-30 days' ) ) ) {
                continue;
            }

            $contenido = '<p>Han pasado más de 6 meses desde tu última actualización de datos.</p>'
                       . '<p>Actualiza tu dirección, teléfono y beneficiario para mantener la información al día.</p>';

            $html = $this->armar_html( $socio->ID, 'Actualiza tus datos', $contenido, array(
                'texto' => 'Actualizar ahora',
                'url'   => esc_url( $this->obtener_configuracion()['enlace_actualizacion_datos'] )
            ) );

            $this->enviar_correo( $socio->user_email, 'Fondo La Unión · Actualiza tus datos', $html );
            update_user_meta( $socio->ID, 'lud_ultima_nota_datos', current_time( 'mysql' ) );
        }
    }

    /**
     * Obtiene los correos de la directiva (roles con permisos de tesorería).
     */
    private function obtener_correos_directiva() {
        $usuarios = get_users( array(
            'role__in' => array( 'administrator', 'lud_presidente', 'lud_secretaria', 'lud_tesorero' ),
            'fields'   => array( 'ID', 'user_email' )
        ) );

        $destinos = array();
        foreach ( $usuarios as $usuario ) {
            if ( empty( $usuario->user_email ) ) {
                continue;
            }
            $destinos[] = array( 'id' => $usuario->ID, 'email' => $usuario->user_email );
        }

        return $destinos;
    }

    /**
     * Envía un correo de prueba usando la plantilla actual.
     */
    public function enviar_correo_prueba( $destino ) {
        $contenido = '<p>Este es un correo de prueba usando la plantilla configurada.</p>'
                   . '<p>Si ves el logo, colores y enlaces correctos, la configuración es válida.</p>';
        $html = $this->armar_html( get_current_user_id(), 'Prueba de notificaciones', $contenido );
        $this->enviar_correo( $destino, 'Fondo La Unión · Prueba de correo', $html );
    }
}

/**
 * Helper global para reutilizar una sola instancia en todo el plugin.
 */
function lud_notificaciones() {
    static $instancia = null;
    if ( is_null( $instancia ) ) {
        $instancia = new LUD_Notificaciones();
    }
    return $instancia;
}
