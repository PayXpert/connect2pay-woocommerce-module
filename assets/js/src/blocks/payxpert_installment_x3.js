import { decodeEntities } from '@wordpress/html-entities';
import { useEffect } from '@wordpress/element';

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;

const settings = getSetting('payxpert_installment_x3_data', {});
const label = decodeEntities(settings.title);

let showCb = false;

const Content = (props) => {
	const contentBlocks = [];
	showCb = (props.billing.billingAddress.country === 'FR');

	if (settings?.description) {
		contentBlocks.push(
			<div
				key="description"
				dangerouslySetInnerHTML={{
					__html: decodeEntities(settings.description),
				}}
			/>
		);
	}

	if (settings?.seamless) {
		const { customerToken, merchantToken, nonce, language, prefix } = settings.seamless;
		const { eventRegistration, emitResponse } = props;
		const { onPaymentSetup } = eventRegistration;

		useEffect(() => {
			const selectedMethod = document.querySelector('input[name="radio-control-wc-payment-method-options"]:checked')?.value;
			if (selectedMethod !== prefix) return;

			const removeHandler = onPaymentSetup(async () => {
				const container = document.querySelector(`.payxpert-seamless-container[data-method="${prefix}"]`);
				if (!container) return { type: emitResponse.responseTypes.ERROR };

				const payxpertNonce = container.querySelector('.payxpert_nonce')?.value;
				const merchantToken = container.querySelector('.merchantToken')?.value;
				const customerToken = container.querySelector('.customerToken')?.value;

				if (!payxpertNonce || !merchantToken || !customerToken) {
					return { type: emitResponse.responseTypes.ERROR };
				}

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							payxpert_nonce: payxpertNonce,
							[`${prefix}_merchant_token`]: merchantToken,
							[`${prefix}_customer_token`]: customerToken,
						},
					},
				};
			});

			return () => {
				if (typeof removeHandler === 'function') {
					removeHandler();
				}
			};
		}, [emitResponse.responseTypes, onPaymentSetup, prefix]);

		useEffect(() => {
			if (typeof window.mountPayxpertIframe === 'function') {
				window.togglePlaceOrder();
				window.mountPayxpertIframe(false);
			}
		}, []);

		useEffect(() => {
			const placeOrderBtn = document.querySelector('#place_order');
			if (!placeOrderBtn) return;

			const handleClick = (e) => {
				const selectedMethod = document.querySelector('input[name="radio-control-wc-payment-method-options"]:checked')?.value;
				if (selectedMethod === prefix) {
					e.preventDefault();
					e.stopImmediatePropagation();
					console.log('[PayXpert] Intercept click');

					const submitBtn = document.getElementById(`payxpert-internal-submit-${prefix}`);
					if (submitBtn) {
						submitBtn.style.display = 'block';

						setTimeout(() => {
							submitBtn.click();
							submitBtn.style.display = 'none';

							setTimeout(() => {
								const placeOrderBtn = document.querySelector('#place_order');
								if (placeOrderBtn) {
									console.log('[PayXpert] Trigger real WooCommerce submission');
									placeOrderBtn.removeEventListener('click', handleClick);
									placeOrderBtn.click();
								}
							}, 100);
						}, 20);
					}
				}
			};

			placeOrderBtn.addEventListener('click', handleClick);

			return () => {
				placeOrderBtn.removeEventListener('click', handleClick);
			};
		}, [prefix]);

	}

	if (contentBlocks.length > 0) {
		return <>{contentBlocks}</>;
	}

	return '';
};

const Label = (props) => {
	const { PaymentMethodLabel } = props.components;
	const cbIcon = 'cb.png';

	if (showCb) {
		if (!settings.icons.includes(cbIcon)) {
			settings.icons.push(cbIcon);
		}
	} else {
		settings.icons = settings.icons.filter(icon => icon !== cbIcon);
	}

	return (
		<span style={{ width: '100%' }}>
			<PaymentMethodLabel text={label} />
			<Icon />
		</span>
	);
};

const Icon = () => {
	if (!Array.isArray(settings.icons) || settings.icons.length === 0) {
		return null;
	}

	return (
		<span style={{ float: 'right' }}>
			{settings.icons.map((filename, index) => (
				<img
					key={index}
					src={`${settings.icon_path}/${filename}`}
					style={{ marginLeft: '5px', verticalAlign: 'middle' }}
					alt={filename}
				/>
			))}
		</span>
	);
};

registerPaymentMethod({
	name: 'payxpert_installment_x3',
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports,
	},
});
