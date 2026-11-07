package billing

// New lets the integration suite inject a store; it exists only in test builds.
var New = newService
