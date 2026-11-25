/** zod building blocks shared by the Block type modules. */
import { z } from 'zod';
import { LIMITS } from './types';

export const str = (max: number) => z.string().max(max, `Keep this under ${max} characters.`).default('');
export const label = str(LIMITS.label);
export const body = str(LIMITS.text);
export const url = str(LIMITS.url);
export const gap = z.enum(['none', 'sm', 'md', 'lg']).default('md');
export const textAlign = z.enum(['inherit', 'left', 'center', 'right', 'justify']).default('inherit');
/** Alignment of a box (no justify). */
export const boxAlign = z.enum(['inherit', 'left', 'center', 'right']).default('inherit');
/** A Block colour (`colors.ts`); `rules` reject values that are not one. */
export const color = str(LIMITS.label);
export const strings = (max: number, item: z.ZodType<string> = z.string().max(LIMITS.cell)) =>
    z.array(item).max(max).default([]);
