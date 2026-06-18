import fetch from 'node-fetch';

const PAYSTACK_SECRET = process.env.PAYSTACK_SECRET_KEY!;

const headers = {
  Authorization: `Bearer ${PAYSTACK_SECRET}`,
  'Content-Type': 'application/json',
};

export const paystack = {
  // Initialize a transaction – returns authorization_url and reference
  async initializeTransaction(params: {
    email: string;
    amount: number; // in kobo (smallest currency unit)
    reference?: string;
    callback_url?: string;
    metadata?: Record<string, any>;
  }) {
    const response = await fetch('https://api.paystack.co/transaction/initialize', {
      method: 'POST',
      headers,
      body: JSON.stringify({
        ...params,
        amount: params.amount * 100, // Paystack expects kobo
      }),
    });
    const data = await response.json();
    if (!data.status) throw new Error(data.message || 'Paystack init failed');
    return data.data; // { authorization_url, reference, access_code }
  },

  // Verify a transaction (call this on webhook or manually)
  async verifyTransaction(reference: string) {
    const response = await fetch(`https://api.paystack.co/transaction/verify/${reference}`, {
      headers,
    });
    const data = await response.json();
    if (!data.status) throw new Error(data.message || 'Verification failed');
    return data.data; // { status, amount, metadata, ... }
  },

  // Create a transfer recipient (for M‑Pesa or bank)
  async createTransferRecipient(params: {
    type: 'mobile_money' | 'bank_account';
    name: string;
    email: string;
    currency: 'KES';
    mobile_money?: { phone: string; provider: 'mpesa' };
    bank_code?: string;
    account_number?: string;
  }) {
    const response = await fetch('https://api.paystack.co/transferrecipient', {
      method: 'POST',
      headers,
      body: JSON.stringify(params),
    });
    const data = await response.json();
    if (!data.status) throw new Error(data.message || 'Recipient creation failed');
    return data.data; // { recipient_code, ... }
  },

  // Initiate a transfer (release funds)
  async initiateTransfer(params: {
    source: 'balance' | 'wallet';
    amount: number; // in kobo
    recipient: string; // recipient_code
    reason?: string;
    reference?: string;
  }) {
    const response = await fetch('https://api.paystack.co/transfer', {
      method: 'POST',
      headers,
      body: JSON.stringify({
        ...params,
        amount: params.amount * 100,
      }),
    });
    const data = await response.json();
    if (!data.status) throw new Error(data.message || 'Transfer failed');
    return data.data; // { transfer_code, ... }
  },

  // Refund a transaction (full or partial)
  async refundTransaction(params: {
    transaction: string | number; // transaction reference or ID
    amount?: number; // optional, in kobo
  }) {
    const response = await fetch('https://api.paystack.co/refund', {
      method: 'POST',
      headers,
      body: JSON.stringify(params),
    });
    const data = await response.json();
    if (!data.status) throw new Error(data.message || 'Refund failed');
    return data.data;
  },
};