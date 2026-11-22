package org.sliid.admin.data

data class DashboardCounts(val pendingReceipts: Int = 0, val confirmedBookings: Int = 0, val ticketsIssued: Int = 0, val checkedInToday: Int = 0)
data class BookingSummary(val id: Int, val reference: String, val purchaser: String, val seats: Int, val total: String, val status: String, val event: String, val createdAt: String)
data class Participant(val name: String, val email: String, val type: String, val nic: String?, val membership: String?, val ticketNumber: String?)
data class BookingDetail(val id: Int, val reference: String, val purchaser: String, val email: String, val phone: String, val event: String, val venue: String, val date: String, val total: String, val status: String, val receiptAvailable: Boolean, val notes: String, val participants: List<Participant>)
data class TicketDetails(val rawValue: String, val number: String, val attendee: String, val attendeeType: String, val identity: String, val event: String, val venue: String, val date: String, val status: String, val valid: Boolean, val checkedIn: Boolean, val checkedInAt: String?)
