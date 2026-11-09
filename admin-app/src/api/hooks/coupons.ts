import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/api/client';
import type { BookingSummary, Cents, Coupon, ISO, ListResponse } from '@/api/types';

export interface CouponStats {
	redemptions: number;
	discount_total_cents: Cents;
}

export type CouponWithStats = Coupon & { stats?: CouponStats };

export interface CouponListParams {
	page?: number;
	per_page?: number;
	active?: '1' | '0' | '';
	search?: string;
}

export interface Redemption {
	booking: BookingSummary;
	discount_cents: Cents;
	created_at: ISO;
}

export type CouponInput = Omit< Coupon, 'id' | 'used_count' | 'created_at' >;

export const couponKeys = {
	all: [ 'coupons' ] as const,
	list: ( params: CouponListParams ) => [ 'coupons', 'list', params ] as const,
	redemptions: ( id: number, page: number ) => [ 'coupons', id, 'redemptions', page ] as const,
};

export function useCoupons( params: CouponListParams ) {
	return useQuery( {
		queryKey: couponKeys.list( params ),
		queryFn: () => api.get< ListResponse< CouponWithStats > >( '/coupons', { ...params } ),
		placeholderData: keepPreviousData,
	} );
}

export function useCouponRedemptions( id: number, page = 1 ) {
	return useQuery( {
		queryKey: couponKeys.redemptions( id, page ),
		queryFn: () =>
			api.get< ListResponse< Redemption > >( `/coupons/${ id }/redemptions`, {
				page,
				per_page: 10,
			} ),
		enabled: id > 0,
	} );
}

export function useSaveCoupon() {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: ( { id, data }: { id?: number; data: Partial< CouponInput > } ) =>
			id
				? api.patch< Coupon >( `/coupons/${ id }`, data )
				: api.post< Coupon >( '/coupons', data ),
		onSuccess: () => qc.invalidateQueries( { queryKey: couponKeys.all } ),
	} );
}

export function useDeleteCoupon() {
	const qc = useQueryClient();
	return useMutation( {
		mutationFn: ( id: number ) =>
			api.del< { deleted?: boolean; deactivated?: boolean } >( `/coupons/${ id }` ),
		onSuccess: () => qc.invalidateQueries( { queryKey: couponKeys.all } ),
	} );
}

export function generateCouponCode( prefix?: string ): Promise< { code: string } > {
	return api.get< { code: string } >( '/coupons/generate-code', { prefix } );
}
