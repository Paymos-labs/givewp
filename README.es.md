# Donaciones en stablecoins con Paymos para GiveWP

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Acepte donaciones puntuales en stablecoins a través del checkout alojado de Paymos en GiveWP. Paymos añade un método de pago en stablecoins a los formularios de donación Visual y clásicos de GiveWP. Los donantes abren el checkout de Paymos desde una página de estado de su sitio, y GiveWP registra una donación confirmada y ofrece su recibo nativo después de que el plugin verifique el pago con Paymos.

[![Ver vídeo: 12 plugins oficiales de pagos cripto para CMS — Conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Recorrido en vídeo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Descripción general

Paymos Stablecoin Donations integra el checkout alojado de Paymos directamente en GiveWP para donaciones puntuales tanto en formularios Visual como en formularios clásicos.

- Los donantes abren el checkout de Paymos desde una página de estado dedicada en su sitio WordPress.
- La confirmación del pago nunca depende solo de la redirección del navegador: GiveWP registra una donación confirmada y muestra su recibo nativo únicamente después de que las notificaciones webhook firmadas y la verificación contra la Merchant API confirmen el pago.
- Admite reembolsos completos y de sobrepago en cadena directamente desde el panel de administración de WordPress.
- Licencia GPLv2 o posterior ([LICENSE](LICENSE)). Versión estable actual: `1.0.1`.

## Requisitos

- WordPress `6.9` o superior (probado hasta `7.1`)
- GiveWP `4.19.1.1` o superior
- PHP `7.4` o superior
- Un sitio WordPress con HTTPS
- Una cuenta y un proyecto de Paymos
- Active el plugin de forma individual en cada sitio (compatible con donaciones puntuales)

## Instalación y conexión

1. Instale y active GiveWP y, a continuación, instale y active **Paymos Stablecoin Donations** (desde el directorio de plugins de WordPress.org o desde [Releases](https://github.com/Paymos-labs/givewp/releases)).
2. Abra **GiveWP → Settings → Payment Gateways → Paymos**. Seleccione **Sandbox** o **Live** para que coincida con el modo de pago de GiveWP.
3. Pulse **Connect Paymos** y apruebe el sitio y el proyecto en la página de aprobación de Paymos.
4. Active Paymos en los formularios Visual o clásicos en los que desee aceptar stablecoins.

## Servicios externos y seguridad

Paymos proporciona el checkout alojado y la Merchant API que utiliza esta pasarela. Consulte los [Términos de Paymos](https://paymos.io/es/terms) y la [Política de privacidad](https://paymos.io/es/privacy).

**Conexión.** Cuando un administrador inicia la conexión, WordPress envía `client_id=paymos-cms` y `scope=cms:bootstrap` a `https://app.paymos.io/connect/cms/device`. El enlace de aprobación del navegador incluye el identificador del plugin `givewp`, la URL del sitio, un código de usuario y la URL de retorno al panel de administración cuando comparte el origen del sitio. Durante la aprobación, WordPress consulta `https://app.paymos.io/connect/token` con la concesión de código de dispositivo (`device_code`), `client_id` y el secreto `device_code`. WordPress guarda las credenciales del entorno seleccionado en una opción cifrada y descarta el token OAuth de corta duración.

**Donaciones.** Cuando el donante abre el checkout de Paymos, WordPress envía `POST /v1/invoices` a `https://api.paymos.io` con `project_id`, `amount`, `currency` y un `external_order_id` aleatorio. Los datos del donante y las credenciales de recibo de GiveWP permanecen en WordPress. El plugin comprueba la factura exacta mediante `GET /v1/invoices/{invoice_id}` y puede localizar una solicitud interrumpida con `GET /v1/invoices` usando el proyecto y la referencia original. Estas solicitudes incluyen un ID de clave, una marca temporal y una firma HMAC. El secreto de firma nunca sale de su sitio.

**Pago y confirmación.** Los donantes abren la factura alojada en `https://checkout.paymos.io`. Paymos envía notificaciones de factura firmadas al webhook de WordPress asociado al proyecto de Paymos conectado. El plugin verifica la firma, consulta la factura exacta en la API y comprueba su alcance y pago antes de actualizar GiveWP. La tarea programada de conciliación de WordPress también revisa las operaciones pendientes a través de la API.

## Reembolsos

El personal autorizado puede solicitar un reembolso completo o de sobrepago desde **GiveWP → Paymos refunds**. WordPress envía una petición firmada `POST /v1/refunds` con `invoice_id`, `mode` y un `external_order_id` aleatorio. Paymos determina el destino a partir del pago original.

El plugin comprueba la operación mediante `GET /v1/refunds/{withdrawal_id}` y, cuando es necesario, la lista filtrada por factura `GET /v1/refunds`. GiveWP registra un reembolso completo después de verificar la operación completada en Paymos. El reembolso del excedente conserva el estado original de la donación.

## Preguntas frecuentes

**¿Cómo puede un donante retomar una donación después de recargar la página?**

Cuando el navegador puede guardar el enlace de recuperación, el donante puede elegir **Resume your previous Paymos donation** en el mismo formulario. El enlace abre la página de estado del sitio y caduca en 12 horas. El sitio verifica el navegador original y la vinculación de GiveWP antes de mostrar el estado o el recibo. Un nuevo envío normal inicia una donación independiente.

**¿Qué ocurre al desconectar o eliminar el plugin?**

Desactivar la pasarela detiene las nuevas donaciones con Paymos y conserva las credenciales necesarias para comprobar las operaciones sin resolver. Desactivar el plugin detiene sus comprobaciones programadas. Eliminarlo borra las credenciales locales, el acceso desde el navegador a sus páginas de estado y las tareas programadas. GiveWP conserva su historial de donaciones. El plugin guarda referencias mínimas de los pagos y reembolsos sin resolver para que un administrador pueda revisarlos manualmente. Gestione las claves y webhooks remotos en el proyecto de Paymos.

## Soporte

- Documentación: [paymos.io/es/docs/cms-givewp](https://paymos.io/es/docs/cms-givewp)
- Código fuente y versiones: [Paymos-labs/givewp](https://github.com/Paymos-labs/givewp)
- Soporte: escriba a [support@paymos.io](mailto:support@paymos.io) para obtener ayuda con el checkout de Paymos, la conexión o los reembolsos.
- Licencia: GPLv2 o posterior ([LICENSE](LICENSE))
