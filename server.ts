import * as dotenv from 'dotenv';
import express, { Request, Response, NextFunction } from 'express';
import cors from 'cors';
import crypto from 'crypto';
import OpenAI from 'openai';
import { createClient } from '@supabase/supabase-js';
import { paystack } from './services/paystack.js'; // adjust path if needed

dotenv.config();

const app = express();
const PORT = process.env.PORT || 5000;

const openai = new OpenAI({ apiKey: process.env.OPENAI_API_KEY });
const supabase = createClient(
  process.env.SUPABASE_URL || '',
  process.env.SUPABASE_SERVICE_ROLE_KEY || ''
);

app.use(cors({ origin: 'http://localhost:5173' }));

// Webhook must use raw body for signature verification
app.use((req: Request, res: Response, next: NextFunction) => {
  if (req.originalUrl === '/api/v1/escrow/webhooks') {
    express.raw({ type: 'application/json' })(req, res, next);
  } else {
    express.json()(req, res, next);
  }
});

// ---------- PAYSTACK ESCROW ENDPOINTS ----------

// 1. Initialize Escrow & Generate Payment Link
app.post('/api/v1/escrow/initialize', async (req: Request, res: Response) => {
  const { clientEmail, providerEmail, providerPhone, amount, description } = req.body;

  try {
    // 1. Create escrow record in Supabase (status: pending_payment)
    const { data: escrow, error } = await supabase
      .from('escrow_agreements')
      .insert({
        client_email: clientEmail,
        provider_email: providerEmail,
        provider_phone: providerPhone,
        amount: parseFloat(amount),
        status: 'pending_payment',
        description,
        created_at: new Date().toISOString(),
      })
      .select()
      .single();

    if (error) throw error;

    // 2. Initialize Paystack transaction
    const paystackData = await paystack.initializeTransaction({
      email: clientEmail,
      amount: parseFloat(amount),
      callback_url: process.env.PAYSTACK_CALLBACK_URL,
      metadata: { escrow_id: escrow.id },
      reference: `escrow_${escrow.id}_${Date.now()}`,
    });

    // 3. Update escrow with payment reference
    await supabase
      .from('escrow_agreements')
      .update({ payment_reference: paystackData.reference })
      .eq('id', escrow.id);

    // 4. Return payment link to frontend
    res.status(200).json({
      success: true,
      paymentLink: paystackData.authorization_url,
      escrowId: escrow.id,
      reference: paystackData.reference,
    });
  } catch (err: any) {
    console.error('Initialize error:', err);
    res.status(500).json({ error: err.message || 'Failed to initialize escrow' });
  }
});

// 2. Webhook for Paystack charge.success events
app.post('/api/v1/escrow/webhooks', async (req: Request, res: Response) => {
  // Verify signature
  const secret = process.env.PAYSTACK_SECRET_KEY!;
  const hash = crypto
    .createHmac('sha512', secret)
    .update(JSON.stringify(req.body))
    .digest('hex');
  if (hash !== req.headers['x-paystack-signature']) {
    return res.status(401).send('Unauthorized');
  }

  const event = req.body;
  if (event.event === 'charge.success') {
    const { reference, metadata, amount, customer } = event.data;
    const escrowId = metadata?.escrow_id;
    if (!escrowId) return res.sendStatus(200); // ignore

    // Update escrow status to 'held'
    await supabase
      .from('escrow_agreements')
      .update({
        status: 'held',
        paid_at: new Date().toISOString(),
        payment_reference: reference,
        amount_paid: parseFloat(amount) / 100, // amount from webhook is in kobo
      })
      .eq('id', escrowId);
  }
  res.sendStatus(200);
});

// 3. Validate Deliverable (unchanged – uses OpenAI)
app.post('/api/v1/agent/escrow/validate-deliverable', async (req: Request, res: Response) => {
  const { escrowId, deliverableImageUrl, criteriaVerificationRules } = req.body;
  try {
    const aiResponse = await openai.chat.completions.create({
      model: 'gpt-4o',
      messages: [
        {
          role: 'user',
          content: [
            { type: 'text', text: `Verify if this document matches these rules: "${criteriaVerificationRules}". Respond strictly with JSON: { "isApproved": boolean, "reasoning": "string" }` },
            { type: 'image_url', image_url: { url: deliverableImageUrl } },
          ],
        },
      ],
      response_format: { type: 'json_object' },
    });

    const decision = JSON.parse(aiResponse.choices[0].message.content || '{}');
    const finalStatus = decision.isApproved ? 'inspection' : 'pending_funding';

    await supabase
      .from('escrow_agreements')
      .update({ status: finalStatus, ai_verification_log: decision, updated_at: new Date().toISOString() })
      .eq('id', escrowId);

    return res.status(200).json({ success: true, decision });
  } catch (err: any) {
    return res.status(500).json({ error: err.message });
  }
});

// 4. Release Funds (after deliverable approved)
app.post('/api/v1/escrow/release', async (req: Request, res: Response) => {
  const { escrowId } = req.body;
  try {
    // 1. Fetch escrow details
    const { data: escrow, error } = await supabase
      .from('escrow_agreements')
      .select('*')
      .eq('id', escrowId)
      .single();
    if (error || !escrow) throw new Error('Escrow not found');
    if (escrow.status !== 'inspection') throw new Error('Escrow not ready for release');

    // 2. Create transfer recipient (provider)
    const recipient = await paystack.createTransferRecipient({
      type: 'mobile_money', // or 'bank_account'
      name: escrow.provider_name || 'Provider',
      email: escrow.provider_email,
      currency: 'KES',
      mobile_money: {
        phone: escrow.provider_phone,
        provider: 'mpesa',
      },
      // For bank: use bank_code and account_number instead
    });

    // 3. Initiate transfer
    const transfer = await paystack.initiateTransfer({
      source: 'balance',
      amount: parseFloat(escrow.amount),
      recipient: recipient.recipient_code,
      reason: `Escrow release for ${escrowId}`,
      reference: `release_${escrowId}_${Date.now()}`,
    });

    // 4. Update escrow status to 'released'
    await supabase
      .from('escrow_agreements')
      .update({
        status: 'released',
        released_at: new Date().toISOString(),
        transfer_code: transfer.transfer_code,
      })
      .eq('id', escrowId);

    res.status(200).json({ success: true, transfer });
  } catch (err: any) {
    console.error('Release error:', err);
    res.status(500).json({ error: err.message });
  }
});

// 5. Refund (optional)
app.post('/api/v1/escrow/refund', async (req: Request, res: Response) => {
  const { escrowId } = req.body;
  try {
    const { data: escrow } = await supabase
      .from('escrow_agreements')
      .select('payment_reference')
      .eq('id', escrowId)
      .single();

    if (!escrow) throw new Error('Escrow not found');

    const refund = await paystack.refundTransaction({
      transaction: escrow.payment_reference,
    });

    await supabase
      .from('escrow_agreements')
      .update({ status: 'refunded', refunded_at: new Date().toISOString() })
      .eq('id', escrowId);

    res.status(200).json({ success: true, refund });
  } catch (err: any) {
    res.status(500).json({ error: err.message });
  }
});

// 6. Fetch escrow details (for frontend)
app.get('/api/v1/escrow/:id', async (req: Request, res: Response) => {
  const { id } = req.params;
  const { data, error } = await supabase
    .from('escrow_agreements')
    .select('*')
    .eq('id', id)
    .single();
  if (error) return res.status(404).json({ error: 'Not found' });
  res.json(data);
});

app.get('/api/v1/escrow/all', async (req: Request, res: Response) => {
  const { data, error } = await supabase
    .from('escrow_agreements')
    .select('*')
    .order('created_at', { ascending: false });
  if (error) return res.status(500).json({ error: error.message });
  res.json({ transactions: data });
});

app.listen(PORT, () => console.log(`[Makao OS] Running on port ${PORT}`));